<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminPage;
use App\Admin\DepartmentAccess;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Member;
use App\Storage\Storage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function __invoke(Request $request, string $id): View
    {
        ['row' => $department] = AdminPage::resolve($request, [
            'load' => fn ($actor): Department => DepartmentAccess::loadOwnDepartment($actor, AdminPage::parseId($id)),
        ]);

        $members = Member::query()->where('department_id', $department->id)->orderBy('order')->orderBy('id')->get()
            ->map(fn (Member $m): array => [
                'id' => $m->id,
                'name' => $m->name,
                'role' => $m->role,
                'parentId' => $m->parent_id,
                'order' => $m->order,
                'imageUrl' => Storage::publicUrl($m->image_path),
            ])->all();

        return view('admin.members.index', ['department' => $department, 'members' => $members]);
    }
}
