<x-layouts.app
    :title="'Org chart - '.$department->name"
    :breadcrumbs="[
        ['title' => 'Dashboard', 'href' => '/dashboard'],
        ['title' => 'Departments', 'href' => '/admin/departments'],
        ['title' => $department->name],
        ['title' => 'Members'],
    ]"
>
    <div class="{{ \App\Admin\Ui::WIDE_PAGE }}">
        <x-admin.page-header
            title="Faculty & org chart"
            :description="'Edit the organizational structure of '.$department->name.' the way it appears on the public page.'"
            backHref="/admin/departments"
        />
        <x-island
            name="admin/members/OrgChartEditor"
            :props="[
                'departmentId' => $department->id,
                'departmentName' => $department->name,
                'departmentSlug' => $department->slug,
                'members' => $members,
            ]"
        />
    </div>
</x-layouts.app>
