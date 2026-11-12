<?php

namespace App\Support;

use App\Models\User;

/**
 * Who is signed in and whether they are a Superadmin. Every action, API route and admin page calls these first;
 * failures are RequestErrors. The Department-user rule (what a non-Superadmin may touch) lives in DepartmentAccess.
 */
final class Authz
{
    public static function isSuperAdmin(?User $actor): bool
    {
        return $actor?->role === User::ROLE_SUPERADMIN;
    }

    public static function requireUser(?User $actor): User
    {
        return $actor ?? throw RequestError::unauthorized();
    }

    public static function requireSuperAdmin(?User $actor): User
    {
        $user = self::requireUser($actor);
        if (! self::isSuperAdmin($user)) {
            throw RequestError::forbidden();
        }

        return $user;
    }
}
