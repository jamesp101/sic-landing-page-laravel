<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminList;
use App\Admin\AdminPage;
use App\Admin\AdminScope;
use App\Admin\DepartmentAccess;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Storage\Storage;
use App\Support\Authz;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DepartmentController extends Controller
{
    public function index(Request $request): View
    {
        ['actor' => $actor] = AdminPage::resolve($request);
        $superadmin = Authz::isSuperAdmin($actor);

        // Each filter is declared once: its query parameter, how it parses, how a value narrows the query.
        $hasFilter = fn (Closure $present, Closure $absent) => AdminList::listFilter(
            fn (?string $raw) => AdminList::parseYesNo($raw),
            fn (bool $has) => $has ? $present : $absent,
        );
        $filters = [
            'logo' => $hasFilter(fn (Builder $q) => $q->whereNotNull('logo_path'), fn (Builder $q) => $q->whereNull('logo_path')),
            'members' => $hasFilter(fn (Builder $q) => $q->whereHas('members'), fn (Builder $q) => $q->whereDoesntHave('members')),
            'posts' => $hasFilter(fn (Builder $q) => $q->whereHas('posts'), fn (Builder $q) => $q->whereDoesntHave('posts')),
        ];

        $countOf = fn (string $table, string $dir) => fn (Builder $q) => $q->orderBy(
            DB::table($table)->selectRaw('count(*)')->whereColumn("{$table}.department_id", 'departments.id'),
            $dir,
        );

        $list = AdminList::runList([
            'url' => $request->getRequestUri(),
            'actor' => $actor,
            'scope' => AdminScope::current($request),
            'restriction' => DepartmentAccess::departmentsWhere($actor),
            'spec' => [
                'fields' => [
                    'name' => AdminList::sortColumn('name'),
                    'members' => AdminList::sortBy(fn (string $dir) => $countOf('members', $dir), 'desc'),
                    'posts' => AdminList::sortBy(fn (string $dir) => $countOf('posts', $dir), 'desc'),
                    'downloads' => AdminList::sortBy(fn (string $dir) => $countOf('downloads', $dir), 'desc'),
                    'updated' => AdminList::sortColumn('updated_at', 'desc'),
                ],
                'defaultSort' => 'name',
                'tiebreak' => AdminList::orderColumn('name', 'asc'),
                'search' => ['name', 'slug', 'description'],
            ],
            'filters' => $filters,
            'count' => fn (Closure $where) => Department::query()->where($where)->count(),
            'fetch' => fn (array $args) => AdminList::apply(
                Department::query()
                    ->select(['id', 'name', 'slug', 'description', 'logo_path', 'updated_at'])
                    ->withCount(['members', 'posts', 'downloads']),
                $args,
            )->get(),
        ]);

        return view('admin.departments.index', ['list' => $list, 'superadmin' => $superadmin]);
    }

    public function create(Request $request): View
    {
        AdminPage::resolve($request, ['superAdmin' => true]);

        return view('admin.departments.create');
    }

    public function edit(Request $request, string $id): View
    {
        ['row' => $row] = AdminPage::resolve($request, [
            'load' => fn ($actor) => DepartmentAccess::loadOwnDepartment($actor, AdminPage::parseId($id)),
        ]);

        return view('admin.departments.edit', ['department' => [
            'id' => $row->id,
            'name' => $row->name,
            'description' => $row->description,
            'heroContent' => $row->hero_content,
            'logoUrl' => Storage::publicUrl($row->logo_path),
        ]]);
    }
}
