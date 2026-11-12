<?php

namespace App\Support;

use App\Models\User;

/**
 * Authorization rules. Every action, API route and admin page calls these first. Failures are RequestErrors.
 * The actor is the signed-in User (`role`, `department_id`).
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

    /**
     * Department users may only touch rows of their own department. Throws NOT_FOUND for a missing row and
     * FORBIDDEN for another department's row. `$row` is any model with a `department_id` attribute.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  T|null  $row
     * @return T
     */
    public static function scopeDepartment(User $actor, ?object $row): object
    {
        if (! $row) {
            throw RequestError::notFound();
        }
        self::assertDepartment($actor, $row->department_id);

        return $row;
    }

    /**
     * Like scopeDepartment for a Department row itself: department users may only touch their own department.
     *
     * @template T of \App\Models\Department
     *
     * @param  T|null  $row
     * @return T
     */
    public static function scopeOwnDepartment(User $actor, ?object $row): object
    {
        if (! $row) {
            throw RequestError::notFound();
        }
        self::assertDepartment($actor, $row->id);

        return $row;
    }

    /** Department id to store on create: forced to the actor's own department for non-superadmins. */
    public static function departmentForWrite(User $actor, ?int $requested): ?int
    {
        if (self::isSuperAdmin($actor)) {
            return $requested;
        }

        return $actor->department_id ?? throw RequestError::forbidden();
    }

    private static function assertDepartment(User $actor, ?int $departmentId): void
    {
        if (self::isSuperAdmin($actor)) {
            return;
        }
        if ($actor->department_id === null || $departmentId !== $actor->department_id) {
            throw RequestError::forbidden();
        }
    }
}
