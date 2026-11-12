<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminList;
use App\Admin\AdminPage;
use App\Admin\AdminScope;
use App\Admin\DepartmentAccess;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Support\RequestError;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        ['actor' => $actor] = AdminPage::resolve($request, ['superAdmin' => true]);

        // Superadmin only: every user is listed, so the Admin scope does not apply (`restriction` matches all).
        $list = AdminList::runList([
            'url' => $request->getRequestUri(),
            'actor' => $actor,
            'scope' => AdminScope::current($request),
            'restriction' => fn (Builder $q) => $q,
            'spec' => [
                'fields' => [
                    'name' => AdminList::sortColumn('name'),
                    'email' => AdminList::sortColumn('email'),
                    'role' => AdminList::sortColumn('role'),
                    'department' => AdminList::sortBy(fn (string $dir) => fn (Builder $q) => $q->orderBy(
                        Department::query()->select('name')->whereColumn('departments.id', 'users.department_id'),
                        $dir,
                    )),
                ],
                'defaultSort' => 'name',
                'tiebreak' => AdminList::orderColumn('id', 'asc'),
                'search' => ['name', 'email'],
            ],
            'count' => fn (Closure $where) => User::query()->where($where)->count(),
            'fetch' => fn (array $args) => AdminList::apply(
                User::query()->select(['id', 'name', 'email', 'role', 'department_id'])->with('department:id,name'),
                $args,
            )->get(),
        ]);

        return view('admin.users.index', ['list' => $list, 'actor' => $actor]);
    }

    public function create(Request $request): View
    {
        ['actor' => $actor] = AdminPage::resolve($request, ['superAdmin' => true]);

        return view('admin.users.create', ['departments' => DepartmentAccess::departmentOptions($actor)]);
    }

    public function edit(Request $request, string $id): View
    {
        ['actor' => $actor, 'row' => $row] = AdminPage::resolve($request, [
            'superAdmin' => true,
            'load' => function () use ($id): User {
                $parsed = AdminPage::parseId($id);

                return ($parsed === null ? null : User::query()->find($parsed)) ?? throw RequestError::notFound();
            },
        ]);

        return view('admin.users.edit', [
            'user' => [
                'id' => $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'role' => $row->role === User::ROLE_SUPERADMIN ? 'superadmin' : 'department_user',
                'departmentId' => $row->department_id,
            ],
            'departments' => DepartmentAccess::departmentOptions($actor),
        ]);
    }
}
