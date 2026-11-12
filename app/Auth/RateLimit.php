<?php

namespace App\Auth;

use App\Support\RequestError;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Fixed-window counters on Laravel's RateLimiter (the cache store, shared by every instance). Keys carry an
 * `app:` prefix. A window starts at its first hit and ends `window` seconds later; expired counters vanish
 * with the cache, so there is nothing to sweep.
 */
final class RateLimit
{
    /** Each rule: `max` attempts per `window` seconds. */
    public const RATE_LIMITS = [
        /** Current-password checks (confirm, change password, delete account, email change), per user. */
        'password' => ['max' => 10, 'window' => 15 * 60],
        /** Anonymous contact form, per client IP. */
        'contact' => ['max' => 5, 'window' => 60 * 60],
        /** Uploads that are not yet attached to a record (inline images, layer images, presigned URLs), per user. */
        'upload' => ['max' => 60, 'window' => 10 * 60],
        /** Password sign-in attempts that stay failed, per normalised email and client IP: the hard block. */
        'signInFailuresPerIp' => ['max' => 10, 'window' => 15 * 60],
        /**
         * Failed password sign-ins per normalised email whatever the client IP: a backstop against guessing spread
         * over many addresses. High enough that one attacker cannot lock a User out with a few requests.
         */
        'signInFailuresPerEmail' => ['max' => 100, 'window' => 15 * 60],
        /** Every password sign-in attempt, success or failure, per client IP (Better Auth's `/sign-in/email` rule). */
        'signInPerIp' => ['max' => 5, 'window' => 60],
        /** `auth.resetPassword`, per client IP (Better Auth's global rule). */
        'authPerIp' => ['max' => 100, 'window' => 60],
        /** `auth.forgotPassword` and `auth.sendVerificationEmail`, each per client IP (Better Auth's rule for its mail endpoints). */
        'mailRequestPerIp' => ['max' => 3, 'window' => 60],
    ];

    /** Counts one attempt for `$key` and returns false once more than `$rule['max']` attempts fall in the current window. */
    public static function consumeRateLimit(string $key, array $rule): bool
    {
        return RateLimiter::hit(self::counter($key), $rule['window']) <= $rule['max'];
    }

    /**
     * Counts one request of an `auth.*` action for `$clientIp`, per action; throws TOO_MANY_REQUESTS over the limit:
     * 3 a minute for `forgotPassword` and `sendVerificationEmail`, 100 a minute for `resetPassword`.
     */
    public static function assertAuthRequestQuota(string $clientIp, string $action): void
    {
        $rule = $action === 'resetPassword' ? self::RATE_LIMITS['authPerIp'] : self::RATE_LIMITS['mailRequestPerIp'];
        if (! self::consumeRateLimit("auth:{$action}:{$clientIp}", $rule)) {
            throw RequestError::tooManyRequests('Too many requests. Please wait a minute.');
        }
    }

    /** Counts one upload (inline image, layer image, presigned URL) for the user; throws TOO_MANY_REQUESTS over the limit. */
    public static function assertUploadQuota(int $userId): void
    {
        if (! self::consumeRateLimit("upload:{$userId}", self::RATE_LIMITS['upload'])) {
            throw RequestError::tooManyRequests('Too many uploads. Try again in a few minutes.');
        }
    }

    /**
     * Counts a password sign-in attempt of `$email` from `$clientIp` against both limits before the password is
     * checked: returns false (and counts nothing) when either the per-address or the per-email limit is already
     * full, so a burst of parallel guesses cannot exceed it. A correct password is refunded with
     * refundSignInAttempt, so only failures stay counted. The hard block is per email and address (one address
     * cannot lock a User out for everyone); the high per-email ceiling backstops guessing spread over many addresses.
     */
    public static function claimSignInAttempt(string $email, string $clientIp): bool
    {
        $ipKey = self::signInKey($email, $clientIp);
        if (! self::claimSlot($ipKey, self::RATE_LIMITS['signInFailuresPerIp'])) {
            return false;
        }
        if (self::claimSlot(self::signInKey($email), self::RATE_LIMITS['signInFailuresPerEmail'])) {
            return true;
        }
        self::refundSlot($ipKey, self::RATE_LIMITS['signInFailuresPerIp']);

        return false;
    }

    /** Gives back the attempt taken by claimSignInAttempt (the sign-in succeeded). */
    public static function refundSignInAttempt(string $email, string $clientIp): void
    {
        self::refundSlot(self::signInKey($email, $clientIp), self::RATE_LIMITS['signInFailuresPerIp']);
        self::refundSlot(self::signInKey($email), self::RATE_LIMITS['signInFailuresPerEmail']);
    }

    private static function counter(string $key): string
    {
        return "app:{$key}";
    }

    /** Hashed so no address is stored. The email is trimmed and lower-cased, so spelling variants share a counter. */
    private static function signInKey(string $email, ?string $clientIp = null): string
    {
        $normalised = mb_strtolower(trim($email));

        return $clientIp === null
            ? 'signin:email:'.hash('sha256', $normalised)
            : 'signin:ip:'.hash('sha256', "{$normalised}\n{$clientIp}");
    }

    /**
     * Takes one slot: increments first, and gives it back at once when that went over the limit, so concurrent
     * callers can never take more than `max` between them. The window is untouched by the give-back.
     */
    private static function claimSlot(string $key, array $rule): bool
    {
        $counter = self::counter($key);
        if (RateLimiter::hit($counter, $rule['window']) <= $rule['max']) {
            return true;
        }
        RateLimiter::decrement($counter, $rule['window']);

        return false;
    }

    /** Gives back one slot taken with claimSlot in the same window (never below zero, never into a new window). */
    private static function refundSlot(string $key, array $rule): void
    {
        $counter = self::counter($key);
        if (RateLimiter::attempts($counter) > 0) {
            RateLimiter::decrement($counter, $rule['window']);
        }
    }
}
