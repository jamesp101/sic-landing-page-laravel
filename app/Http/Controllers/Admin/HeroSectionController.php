<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminList;
use App\Admin\AdminPage;
use App\Admin\AdminScope;
use App\Admin\DepartmentAccess;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\HeroSection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class HeroSectionController extends Controller
{
    public function index(Request $request)
    {
        ['actor' => $actor] = AdminPage::resolve($request);
        $scope = AdminScope::current($request);

        $list = AdminList::runList([
            'url' => $request->getRequestUri(),
            'actor' => $actor,
            'scope' => $scope,
            'spec' => [
                'fields' => [
                    'title' => AdminList::sortColumn('title'),
                    'department' => AdminList::sortBy(fn (string $dir) => fn (Builder $q) => $q->orderBy(
                        Department::query()->select('name')->whereColumn('departments.id', 'hero_sections.department_id'),
                        $dir,
                    )),
                    'order' => AdminList::sortColumn('order'),
                    'active' => AdminList::sortColumn('is_active'),
                ],
                'defaultSort' => 'order',
                'tiebreak' => AdminList::orderColumn('id', 'asc'),
                'search' => ['title', 'search_text'],
            ],
            'count' => fn ($where) => HeroSection::query()->where($where)->count(),
            'fetch' => fn (array $args) => AdminList::apply(
                HeroSection::query()
                    ->select(['id', 'title', 'search_text', 'order', 'is_active', 'department_id'])
                    ->with('department:id,name'),
                $args,
            )->get(),
        ]);

        return view('admin.hero-sections.index', [
            'list' => $list,
            'sections' => $list->rows,
            'showLocation' => $scope['kind'] === 'all',
        ]);
    }

    public function create(Request $request)
    {
        ['actor' => $actor] = AdminPage::resolve($request);

        return view('admin.hero-sections.create', [
            'departments' => DepartmentAccess::departmentOptions($actor),
            'isSuperAdmin' => $actor->isSuperAdmin(),
            'defaultDepartmentId' => AdminScope::scopeDefaultDepartment(AdminScope::current($request)),
        ]);
    }

    public function edit(Request $request, string $id)
    {
        ['actor' => $actor, 'row' => $section] = AdminPage::resolve($request, [
            'load' => fn ($actor) => DepartmentAccess::loadScoped($actor, 'heroSection', AdminPage::parseId($id)),
        ]);

        return view('admin.hero-sections.edit', [
            'section' => $section,
            'departments' => DepartmentAccess::departmentOptions($actor),
            'isSuperAdmin' => $actor->isSuperAdmin(),
            'values' => [
                'id' => $section->id,
                'title' => $section->title,
                'content' => $section->content,
                'order' => $section->order,
                'isActive' => $section->is_active,
                'departmentId' => $section->department_id,
            ],
        ]);
    }
}
