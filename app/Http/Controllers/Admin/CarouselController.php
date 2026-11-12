<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminPage;
use App\Admin\AdminScope;
use App\Admin\DepartmentAccess;
use App\Carousel\Slides;
use App\Http\Controllers\Controller;
use App\Models\CarouselItem;
use App\Support\Authz;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CarouselController extends Controller
{
    public function __invoke(Request $request): View
    {
        ['actor' => $actor] = AdminPage::resolve($request);

        $superadmin = Authz::isSuperAdmin($actor);
        // Slides follow the admin scope: `main` = slides without a department, a department = that department's slides.
        $scope = AdminScope::current($request);
        $slides = CarouselItem::query()
            ->where(AdminScope::departmentWhere($actor, $scope))
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(Slides::toDesignerSlide(...))
            ->all();
        $requested = (int) $request->query('slide');
        $ids = array_column($slides, 'id');

        return view('admin.carousels.index', [
            'slides' => $slides,
            'departments' => DepartmentAccess::departmentOptions($actor),
            'initialId' => in_array($requested, $ids, true) ? $requested : ($ids[0] ?? null),
            'scopeName' => $superadmin ? AdminScope::scopeLabel($scope) : null,
            'defaultDepartmentId' => AdminScope::scopeDefaultDepartment($scope),
            'superadmin' => $superadmin,
            'lockedDepartmentId' => $superadmin ? null : $actor->department_id,
        ]);
    }
}
