<?php

namespace App\Auth;

use App\Models\User;
use App\Support\RequestError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Signing in, signing out and naming the current session, in one place. Both the `auth.login` action and the
 * no-JS `/login` POST sign in through here, so the same rate limit applies.
 */
final class AuthSession
{
    /**
     * A bcrypt hash (cost 12, the cost of the hashes Better Auth wrote and of production's BCRYPT_ROUNDS) nobody's
     * password matches. Checked when the email is unknown so the answer takes one bcrypt check either way.
     * Precomputed: hashing per request would cost a second bcrypt operation and reveal unknown emails by timing.
     */
    private const DUMMY_HASH = '$2y$12$pQLYeVZo3lRUi5/ccZRviOMdJnLfbVN6MeyxK2u0Y3LfNNj2joKvu';

    /**
     * Signs in with email and password and reports a failure once, as `['status' => 429|422, 'message' => string]`
     * (null on success). 429 when rate limited, else 422 with one message for any other failure so it does not
     * reveal which part was wrong. A slot is claimed before the password is checked and given back on success, so
     * only failures count.
     *
     * @return array{status: int, message: string}|null
     */
    public static function attemptSignIn(Request $request, string $email, string $password, bool $remember): ?array
    {
        $clientIp = $request->ip() ?? 'unknown';
        // Every attempt counts here, successful or not, before the failure-only limits below.
        if (! RateLimit::consumeRateLimit("signin-ip:{$clientIp}", RateLimit::RATE_LIMITS['signInPerIp'])
            || ! RateLimit::claimSignInAttempt($email, $clientIp)) {
            return ['status' => 429, 'message' => 'Too many attempts. Please wait a few minutes and try again.'];
        }

        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
        // The database may match case- and accent-insensitively (`ádmin@…` finds `admin@…`), but the rate limit keys
        // and the account are exact: anything but the same lower-cased address is "not found".
        if ($user !== null && mb_strtolower($user->email) !== mb_strtolower(trim($email))) {
            $user = null;
        }
        $valid = Hash::check($password, $user?->password ?: self::DUMMY_HASH) && $user !== null;
        if (! $valid) {
            return ['status' => 422, 'message' => 'These credentials do not match our records.'];
        }

        // Starts a fresh session id, so a session id planted before sign-in is useless.
        Auth::guard('web')->login($user, $remember);
        RateLimit::refundSignInAttempt($email, $clientIp);

        return null;
    }

    /** Like `attemptSignIn`, but throws a failure as a RequestError: TOO_MANY_REQUESTS, else UNAUTHORIZED. */
    public static function signIn(Request $request, string $email, string $password, bool $remember): void
    {
        $failure = self::attemptSignIn($request, $email, $password, $remember);
        if ($failure !== null) {
            throw $failure['status'] === 429
                ? RequestError::tooManyRequests($failure['message'])
                : RequestError::unauthorized($failure['message']);
        }
    }

    /** Signs out the current session. */
    public static function signOut(Request $request): void
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /** The id of the signed-in request's session (the `sessions.id` row), or null when signed out. */
    public static function currentSessionId(Request $request): ?string
    {
        return $request->user() && $request->hasSession() ? $request->session()->getId() : null;
    }
}
