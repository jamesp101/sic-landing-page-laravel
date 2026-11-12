<?php

namespace App\Auth;

/** The password policy, stated once. Actions, `sic:create-admin` and the forms' hints use it. */
final class PasswordPolicy
{
    /** Minimum length of any User's password (set by a Superadmin, changed in settings, or reset by email). */
    public const MIN_PASSWORD_LENGTH = 8;

    /**
     * Minimum length of the password given to `sic:create-admin`. Deliberately longer than
     * MIN_PASSWORD_LENGTH: that command creates or resets a production Superadmin from a shell, where no
     * one else reviews the choice, and a Superadmin can work on every Department.
     */
    public const MIN_ADMIN_PASSWORD_LENGTH = 12;

    /** Longest password accepted when a User changes their own. */
    public const MAX_PASSWORD_LENGTH = 128;

    /** The error shown when a password is shorter than `$min`. */
    public static function passwordTooShort(int $min = self::MIN_PASSWORD_LENGTH): string
    {
        return "The password must be at least {$min} characters.";
    }

    /** The form hint that states the minimum. */
    public static function passwordMinHint(int $min = self::MIN_PASSWORD_LENGTH): string
    {
        return "Minimum {$min} characters.";
    }
}
