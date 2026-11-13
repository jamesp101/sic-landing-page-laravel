<?php

namespace App\Admin;

use App\Models\Department;
use App\Models\Member;
use App\Models\User;
use App\Support\Authz;
use App\Support\RequestError;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The Department-user rule, stated once: a Superadmin sees and changes every Department's rows (and the main
 * page / General, `department_id` null); any other actor sees and changes only their own Department's rows.
 * Reads (`restrictedDepartmentId`, `visibleDepartments`, `departmentOptions`, `departmentField`), writes
 * (`resolveWriteDepartment`) and loads (`loadScoped`, `loadScopedMany`, `loadOwnDepartment`, `loadMember`)
 * all go through here. `AdminScope` builds list filters from `restrictedDepartmentId`.
 */
final class DepartmentAccess
{
    /** Matches no Department: the restriction of a department user who has no Department assigned. */
    private const NO_DEPARTMENT = -1;

    /** Validation rules of a `departmentId` input of create/update actions: null/absent = main page or General. */
    public static function departmentIdRules(): array
    {
        return ['nullable', 'integer'];
    }

    /** The only Department id the actor may see or change: null = unrestricted (Superadmin). */
    public static function restrictedDepartmentId(User $actor): ?int
    {
        return Authz::isSuperAdmin($actor) ? null : ($actor->department_id ?? self::NO_DEPARTMENT);
    }

    /**
     * Constraint for a query over the `departments` table itself (Department rows are not owned by a
     * Department: a department user's restriction is their own row, `id`).
     *
     * @return Closure(Builder): void
     */
    public static function departmentsWhere(User $actor): Closure
    {
        $only = self::restrictedDepartmentId($actor);

        return static fn (Builder $q) => $only === null ? $q : $q->where('id', $only);
    }

    /** Departments the actor may see, as a query. */
    public static function visibleDepartments(User $actor): Builder
    {
        return Department::query()->where(self::departmentsWhere($actor));
    }

    /**
     * Departments the actor may pick in a form: all for superadmins, otherwise only their own.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function departmentOptions(User $actor): array
    {
        return self::visibleDepartments($actor)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $d) => ['id' => (int) $d->id, 'name' => $d->name])
            ->all();
    }

    /**
     * The finished Department field of a form for a row that exists (`$departmentId` is its Department, null =
     * Main site): what the island prints and never decides. A Superadmin chooses among every Department and the
     * Main site (`allowMain`); any other actor is `locked` to their own Department. `value` is the selected
     * Department id (null = Main site, or a Department user without a Department, who has no option at all).
     *
     * @return array{options: list<array{id: int, name: string}>, value: ?int, locked: bool, allowMain: bool}
     */
    public static function departmentField(User $actor, ?int $departmentId): array
    {
        $options = self::departmentOptions($actor);
        $superadmin = Authz::isSuperAdmin($actor);

        return [
            'options' => $options,
            'value' => $superadmin ? $departmentId : ($options[0]['id'] ?? null),
            'locked' => ! $superadmin,
            'allowMain' => $superadmin,
        ];
    }

    /**
     * The Department field of a create form: a Superadmin starts on the Admin scope's Department (the Main site
     * unless the scope is one Department), a Department user on their own.
     *
     * @return array{options: list<array{id: int, name: string}>, value: ?int, locked: bool, allowMain: bool}
     */
    public static function createDepartmentField(User $actor, Request $request): array
    {
        return self::departmentField($actor, AdminScope::scopeDefaultDepartment(AdminScope::current($request)));
    }

    /** Throws BAD_REQUEST unless the department exists. */
    public static function assertDepartmentExists(int $id): void
    {
        if (! Department::query()->whereKey($id)->exists()) {
            throw RequestError::badRequest('The selected department does not exist.');
        }
    }

    /**
     * Department id to store on a create/update: superadmins choose (null = main page / General), anyone else
     * is forced to their own department (FORBIDDEN without one); a chosen department must exist.
     */
    public static function resolveWriteDepartment(User $actor, ?int $requested): ?int
    {
        $departmentId = Authz::isSuperAdmin($actor)
            ? $requested
            : ($actor->department_id ?? throw RequestError::forbidden());
        if ($departmentId !== null) {
            self::assertDepartmentExists($departmentId);
        }

        return $departmentId;
    }

    /**
     * Loads one row by id for the actor: NOT_FOUND when missing (or `$id` is null), FORBIDDEN for another
     * department's row (or any row without a department, for a department user).
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model  a model with a `department_id` attribute
     * @return T
     */
    public static function loadScoped(User $actor, string $model, ?int $id): Model
    {
        if ($id === null) {
            throw RequestError::notFound();
        }

        return self::assertOwnRow($actor, $model::query()->find($id));
    }

    /**
     * Loads rows for a batch of distinct ids, returned in `$ids` order: NOT_FOUND if any id is missing,
     * FORBIDDEN if any row belongs to another department.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @param  list<int>  $ids
     * @return list<T>
     */
    public static function loadScopedMany(User $actor, string $model, array $ids): array
    {
        $byId = $model::query()->whereIn('id', $ids)->get()->keyBy('id');

        return array_map(fn (int $id) => self::assertOwnRow($actor, $byId->get($id)), $ids);
    }

    /** Loads a department the actor may manage (their own; any for superadmins): NOT_FOUND / FORBIDDEN otherwise. */
    public static function loadOwnDepartment(User $actor, ?int $id): Department
    {
        $department = $id === null ? null : Department::query()->find($id);
        if (! $department) {
            throw RequestError::notFound();
        }
        self::assertDepartment($actor, $department->id);

        return $department;
    }

    /** Loads a member of `$departmentId`, which the actor must be allowed to manage: NOT_FOUND / FORBIDDEN otherwise. */
    public static function loadMember(User $actor, int $departmentId, int $id): Member
    {
        self::loadOwnDepartment($actor, $departmentId);
        $member = Member::query()->find($id);
        if (! $member || (int) $member->department_id !== $departmentId) {
            throw RequestError::notFound();
        }

        return $member;
    }

    private static function assertOwnRow(User $actor, ?Model $row): Model
    {
        if (! $row) {
            throw RequestError::notFound();
        }
        self::assertDepartment($actor, $row->department_id);

        return $row;
    }

    private static function assertDepartment(User $actor, ?int $departmentId): void
    {
        $only = self::restrictedDepartmentId($actor);
        if ($only !== null && $departmentId !== $only) {
            throw RequestError::forbidden();
        }
    }
}
