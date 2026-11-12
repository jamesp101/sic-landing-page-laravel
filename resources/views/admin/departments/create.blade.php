<x-layouts.app
    title="New department"
    :breadcrumbs="[
        ['title' => 'Dashboard', 'href' => '/dashboard'],
        ['title' => 'Departments', 'href' => '/admin/departments'],
        ['title' => 'New'],
    ]"
>
    <div class="{{ \App\Admin\Ui::FORM_PAGE }}">
        <x-admin.page-header
            title="New department"
            description="Add an academic department or administrative unit."
            backHref="/admin/departments"
        />
        <x-island name="admin/departments/DepartmentForm" :props="[]" />
    </div>
</x-layouts.app>
