<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminPage;
use App\Admin\AdminScope;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Download;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        ['actor' => $actor] = AdminPage::resolve($request);

        $scope = AdminScope::current($request);
        $where = AdminScope::departmentWhere($actor, $scope);

        return view('admin.dashboard', [
            'actor' => $actor,
            'scope' => $scope,
            'postsCount' => Post::query()->where($where)->count(),
            'downloadsCount' => Download::query()->where($where)->count(),
            'departmentsCount' => Department::query()->count(),
            'usersCount' => User::query()->count(),
        ]);
    }
}
