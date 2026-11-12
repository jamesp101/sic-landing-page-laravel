<?php

namespace App\Admin;

use App\Models\CarouselItem;
use App\Models\Department;
use App\Models\Download;
use App\Models\DownloadDirectory;
use App\Models\HeroSection;
use App\Models\Member;
use App\Models\Post;
use App\Models\User;
use App\Support\Authz;
use App\Support\RequestError;
use Illuminate\Database\Eloquent\Model;

/**
 * Department-scoped admin access that needs the database: which department a write may target, and loading
 * one row the actor is allowed to touch. The pure rules live in Authz (`scopeDepartment`, `departmentForWrite`)
 * and AdminScope (`departmentWhere`).
 */
final class DepartmentAccess
{
    /** Models `loadScoped` can load, by the camelCase name the TS source uses. */
    private const MODELS = [
        'post' => Post::class,
        'carouselItem' => CarouselItem::class,
        'heroSection' => HeroSection::class,
        'download' => Download::class,
        'downloadDirectory' => DownloadDirectory::class,
    ];

    /** Validation rules of a `departmentId` input of create/update actions: null/absent = main page or General. */
    public static function departmentIdRules(): array
    {
        return ['nullable', 'integer'];
    }

    /**
     * Departments the actor may pick in a form: all for superadmins, otherwise only their own.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function departmentOptions(User $actor): array
    {
        return Department::query()
            ->when(! Authz::isSuperAdmin($actor), fn ($q) => $q->where('id', $actor->department_id ?? -1))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $d) => ['id' => (int) $d->id, 'name' => $d->name])
            ->all();
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
        $departmentId = Authz::departmentForWrite($actor, $requested);
        if ($departmentId !== null) {
            self::assertDepartmentExists($departmentId);
        }

        return $departmentId;
    }

    /** Loads one row by id for the actor: NOT_FOUND when missing (or `$id` is null), FORBIDDEN for another department's row. */
    public static function loadScoped(User $actor, string $model, ?int $id): Model
    {
        if ($id === null) {
            throw RequestError::notFound();
        }

        return Authz::scopeDepartment($actor, self::modelClass($model)::query()->find($id));
    }

    /**
     * Loads rows for a batch of distinct ids, returned in `$ids` order: NOT_FOUND if any id is missing,
     * FORBIDDEN if any row belongs to another department.
     *
     * @param  list<int>  $ids
     * @return list<Model>
     */
    public static function loadScopedMany(User $actor, string $model, array $ids): array
    {
        $byId = self::modelClass($model)::query()->whereIn('id', $ids)->get()->keyBy('id');

        return array_map(fn (int $id) => Authz::scopeDepartment($actor, $byId->get($id)), $ids);
    }

    /** Loads a department the actor may manage (their own; any for superadmins): NOT_FOUND / FORBIDDEN otherwise. */
    public static function loadOwnDepartment(User $actor, ?int $id): Department
    {
        if ($id === null) {
            throw RequestError::notFound();
        }

        return Authz::scopeOwnDepartment($actor, Department::query()->find($id));
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

    /** @return class-string<Model> */
    private static function modelClass(string $model): string
    {
        return self::MODELS[$model] ?? throw new \InvalidArgumentException("Unknown scoped model: {$model}");
    }
}
