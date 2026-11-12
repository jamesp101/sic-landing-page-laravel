<?php

namespace App\Admin;

use App\Models\User;
use App\Support\Authz;
use Illuminate\Http\Request;

/**
 * The page adapter for admin, settings and dashboard pages. Rejections are RequestErrors (401/403/404) that
 * bootstrap/app.php renders as the HTTP error page; pages carry no try/catch of their own.
 *
 *   ['actor' => $actor, 'row' => $post] = AdminPage::resolve($request, [
 *       'load' => fn (User $actor) => DepartmentAccess::loadScoped($actor, Post::class, AdminPage::parseId($id)),
 *   ]);
 */
final class AdminPage
{
    /**
     * Resolves the signed-in actor (and the scoped row, if `load` is given).
     *
     * @param  array{superAdmin?: bool, load?: callable(User): mixed}  $options
     * @return array{actor: User, row: mixed}
     */
    public static function resolve(Request $request, array $options = []): array
    {
        $user = $request->user();
        $actor = ! empty($options['superAdmin']) ? Authz::requireSuperAdmin($user) : Authz::requireUser($user);
        $load = $options['load'] ?? null;

        return ['actor' => $actor, 'row' => $load ? $load($actor) : null];
    }

    /** Parses a numeric route segment; null for anything that is not a positive integer. */
    public static function parseId(?string $value): ?int
    {
        return $value !== null && preg_match('/^\d+$/', $value) ? (int) $value : null;
    }
}
