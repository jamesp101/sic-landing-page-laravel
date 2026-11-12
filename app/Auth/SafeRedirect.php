<?php

namespace App\Auth;

final class SafeRedirect
{
    /** Accepts only same-origin relative paths (`/foo?bar`); anything else falls back. */
    public static function safeRedirect(?string $value, string $fallback = '/dashboard'): string
    {
        if ($value === null || $value === '' || ! str_starts_with($value, '/') || str_starts_with($value, '//') || str_contains($value, '\\')) {
            return $fallback;
        }
        if (preg_match('/\p{Cc}/u', $value) === 1 || ! mb_check_encoding($value, 'UTF-8')) {
            return $fallback;
        }

        return $value;
    }
}
