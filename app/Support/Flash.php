<?php

namespace App\Support;

use Illuminate\Support\Facades\Cookie;

/**
 * A toast queued for the next rendered admin/settings layout. It travels in a short-lived httpOnly cookie
 * (`sic_flash`), not in the session, so it reaches the browser that made the change and no other one signed
 * in with the same session.
 */
final class Flash
{
    public const COOKIE = 'sic_flash';

    /** Longest message kept; the rest is cut. */
    public const MAX_LENGTH = 300;

    private const MINUTES = 1;

    public static function success(string $message): void
    {
        self::put('success', $message);
    }

    public static function error(string $message): void
    {
        self::put('error', $message);
    }

    /** The cookie value for a toast (also what tests compare against). */
    public static function encode(string $type, string $message): string
    {
        return json_encode(
            ['type' => $type, 'message' => mb_substr($message, 0, self::MAX_LENGTH)],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Reads the toast of the current request and clears it.
     *
     * @return array{type: 'success'|'error', message: string}|null
     */
    public static function take(): ?array
    {
        $raw = request()->cookie(self::COOKIE);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        Cookie::queue(Cookie::forget(self::COOKIE));
        $flash = json_decode($raw, true);
        if (! is_array($flash) || ! in_array($flash['type'] ?? null, ['success', 'error'], true) || ! is_string($flash['message'] ?? null)) {
            return null;
        }

        return ['type' => $flash['type'], 'message' => $flash['message']];
    }

    private static function put(string $type, string $message): void
    {
        Cookie::queue(Cookie::make(self::COOKIE, self::encode($type, $message), self::MINUTES, '/', null, null, true, false, 'lax'));
    }
}
