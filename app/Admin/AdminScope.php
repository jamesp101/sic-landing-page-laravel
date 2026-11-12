<?php

namespace App\Admin;

use App\Models\Department;
use App\Models\User;
use App\Support\Authz;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Admin scope: the department context chosen in the sidebar switcher.
 * `departmentWhere` is the only way to turn (actor, scope) into a list filter and `listWhere` the only way
 * to add more conditions to it: the actor's restriction always wins over the scope and over any search or
 * filter. Both return a closure over an Eloquent builder (column `department_id`):
 * `Post::query()->where(AdminScope::departmentWhere($actor, $scope))`.
 *
 * A scope is `['kind' => 'all']`, `['kind' => 'main']` or `['kind' => 'department', 'id', 'name', 'slug']`.
 */
final class AdminScope
{
    public const SCOPE_COOKIE = 'admin_scope';

    private const REQUEST_SCOPE = 'admin_scope.resolved';

    private const REQUEST_DEPARTMENTS = 'admin_scope.departments';

    /**
     * Cookie value (`all` | `main` | `<id>`) to a parsed scope; anything unrecognised is `all`.
     *
     * @return array{kind: string, id?: int}
     */
    public static function parseScope(?string $cookieValue): array
    {
        $value = trim((string) $cookieValue);
        if ($value === 'main') {
            return ['kind' => 'main'];
        }
        if (preg_match('/^[1-9]\d{0,9}$/', $value)) {
            return ['kind' => 'department', 'id' => (int) $value];
        }

        return ['kind' => 'all'];
    }

    /**
     * Effective scope for the actor. Superadmins get the requested scope (unknown department ids fall back to
     * `all`); department users are always scoped to their own department and ignore the cookie.
     *
     * @param  iterable<array{id: int, name: string, slug: string}|Department>  $departments
     */
    public static function resolveScope(User $actor, array $parsed, iterable $departments): array
    {
        $find = static function (?int $id) use ($departments): ?array {
            foreach ($departments as $department) {
                $row = is_array($department) ? $department : $department->only(['id', 'name', 'slug']);
                if ((int) $row['id'] === $id) {
                    return ['kind' => 'department', 'id' => (int) $row['id'], 'name' => $row['name'], 'slug' => $row['slug']];
                }
            }

            return null;
        };

        if (! Authz::isSuperAdmin($actor)) {
            return $find($actor->department_id) ?? ['kind' => 'all'];
        }
        if ($parsed['kind'] === 'department') {
            return $find($parsed['id']) ?? ['kind' => 'all'];
        }

        return ['kind' => $parsed['kind']];
    }

    /**
     * Constraint for rows with a nullable `department_id`. Department users always get their own department
     * (the scope is ignored for them); superadmins get what the scope selects (`main` = rows with no
     * department). `includeGeneral` also matches rows with no department (shared download folders).
     *
     * @param  array{includeGeneral?: bool}  $options
     * @return Closure(Builder): void
     */
    public static function departmentWhere(User $actor, array $scope, array $options = []): Closure
    {
        $includeGeneral = $options['includeGeneral'] ?? false;
        $only = static fn (int $id): Closure => $includeGeneral
            ? static fn (Builder $q) => $q->where(static fn (Builder $q) => $q->where('department_id', $id)->orWhereNull('department_id'))
            : static fn (Builder $q) => $q->where('department_id', $id);

        if (! Authz::isSuperAdmin($actor)) {
            return $only($actor->department_id ?? -1);
        }
        if ($scope['kind'] === 'main') {
            return static fn (Builder $q) => $q->whereNull('department_id');
        }
        if ($scope['kind'] === 'department') {
            return $only($scope['id']);
        }

        return static function (Builder $q): void {};
    }

    /**
     * `departmentWhere` plus extra list conditions (closures over the builder; search, type, flags), ANDed so
     * none of them, including an OR search, can overwrite or widen the actor's restriction. Empty entries are skipped.
     *
     * @param  array<int, Closure|string|null|false>  $filters
     * @param  array{includeGeneral?: bool}  $options
     * @return Closure(Builder): void
     */
    public static function listWhere(User $actor, array $scope, array $filters, array $options = []): Closure
    {
        $restriction = self::departmentWhere($actor, $scope, $options);
        $extra = array_values(array_filter($filters, static fn ($f) => $f instanceof Closure));

        return static function (Builder $q) use ($restriction, $extra): void {
            $q->where($restriction);
            foreach ($extra as $filter) {
                $q->where($filter);
            }
        };
    }

    /** Human label used by the switcher and page indicators. */
    public static function scopeLabel(array $scope): string
    {
        return match ($scope['kind']) {
            'all' => 'All departments',
            'main' => 'Main page',
            default => $scope['name'],
        };
    }

    /** Department id a create form should preselect: the scoped department, otherwise null (General / main page). */
    public static function scopeDefaultDepartment(array $scope): ?int
    {
        return $scope['kind'] === 'department' ? $scope['id'] : null;
    }

    /**
     * The resolved scope of the signed-in user for this request (cookie `admin_scope`; department users are
     * pinned). Same shape as Astro's `locals.adminScope`; `all` when nobody is signed in.
     */
    public static function current(Request $request): array
    {
        if (! $request->attributes->has(self::REQUEST_SCOPE)) {
            self::resolveRequest($request);
        }

        return $request->attributes->get(self::REQUEST_SCOPE);
    }

    /**
     * The departments the switcher lists (`locals.adminDepartments`): all for superadmins, only their own
     * for department users, none when signed out. Rows are `['id', 'name', 'slug', 'logoPath']`.
     *
     * @return list<array{id: int, name: string, slug: string, logoPath: ?string}>
     */
    public static function departments(Request $request): array
    {
        if (! $request->attributes->has(self::REQUEST_DEPARTMENTS)) {
            self::resolveRequest($request);
        }

        return $request->attributes->get(self::REQUEST_DEPARTMENTS);
    }

    private static function resolveRequest(Request $request): void
    {
        $user = $request->user();
        $departments = [];
        $scope = ['kind' => 'all'];
        if ($user instanceof User) {
            $departments = Department::query()
                ->when(! Authz::isSuperAdmin($user), fn (Builder $q) => $q->where('id', $user->department_id ?? -1))
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'logo_path'])
                ->map(fn (Department $d) => [
                    'id' => (int) $d->id,
                    'name' => $d->name,
                    'slug' => $d->slug,
                    'logoPath' => $d->logo_path,
                ])
                ->all();
            $scope = self::resolveScope($user, self::parseScope(self::cookieValue($request, self::SCOPE_COOKIE)), $departments);
        }
        $request->attributes->set(self::REQUEST_SCOPE, $scope);
        $request->attributes->set(self::REQUEST_DEPARTMENTS, $departments);
    }

    /**
     * Reads a cookie from the raw Cookie header: cookies written by browser scripts are not encrypted, so
     * Laravel's cookie decryption would discard them.
     */
    public static function cookieValue(Request $request, string $name): ?string
    {
        foreach (explode(';', (string) $request->headers->get('Cookie')) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, null);
            if ($key === $name && $value !== null) {
                return urldecode($value);
            }
        }

        return null;
    }
}
