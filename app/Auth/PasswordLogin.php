<?php

namespace App\Auth;

use App\Models\User;
use App\Support\RequestError;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A User's Password login: the bcrypt hash in `users.password`. Everything that sets, replaces or checks it goes
 * through here. Existing `$2y$` hashes keep working.
 */
final class PasswordLogin
{
    /**
     * Replaces the User's password hash (`$hash` from Hash::make). Joins the caller's transaction.
     *
     * `$options['endSessions']` ends the User's sessions in the same write: all of them (`[]`), or all but
     * `['except' => $sessionId]` (the session the caller is using). Omit to leave sessions alone.
     *
     * @param  array{endSessions?: array{except?: string|null}}  $options
     */
    public static function setPasswordLogin(int $userId, string $hash, array $options = []): void
    {
        DB::transaction(function () use ($userId, $hash, $options): void {
            // Query-builder update: the model's `hashed` cast must not touch a hash that is already made.
            User::query()->whereKey($userId)->update(['password' => $hash]);
            if (isset($options['endSessions'])) {
                self::endSessions($userId, $options['endSessions']['except'] ?? null);
            }
        });
    }

    /**
     * Ends the User's sessions in one write (rows of the `sessions` table, so they stop at once): all of them, or
     * all but `$exceptSessionId` (the session the caller is using). The one writer of the `sessions` table: also used
     * when a changed email address must sign out other devices and when a User is deleted.
     */
    public static function endSessions(int $userId, ?string $exceptSessionId = null): void
    {
        // A remember-me cookie names the User by `remember_token`: rotating it ends every remembered device.
        User::query()->whereKey($userId)->update(['remember_token' => Str::random(60)]);
        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();

        // "End the other sessions, keep mine": the current device, if it was remembered, gets a fresh cookie
        // (also bound to the User's current password hash, which may just have changed).
        $guard = Auth::guard('web');
        if ($exceptSessionId !== null && $guard->id() === $userId && request()->cookies->has($guard->getRecallerName())) {
            $guard->login(User::query()->findOrFail($userId), true);
        }
    }

    /** The stored hash of the User's Password login, or null when the User has none. */
    public static function currentPasswordHash(int $userId): ?string
    {
        $hash = User::query()->whereKey($userId)->value('password');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /** Verifies the User's current password; attempts are rate-limited per user. Throws BAD_REQUEST / TOO_MANY_REQUESTS. */
    public static function assertPassword(int $userId, string $password): void
    {
        if (! RateLimit::consumeRateLimit("password:{$userId}", RateLimit::RATE_LIMITS['password'])) {
            throw RequestError::tooManyRequests('Too many password attempts. Try again in 15 minutes.');
        }
        $hash = self::currentPasswordHash($userId);
        if ($hash === null || ! Hash::check($password, $hash)) {
            throw RequestError::badRequest('The provided password was incorrect.');
        }
    }
}
