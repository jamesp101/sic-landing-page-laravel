<x-layouts.app
    :title="'Edit '.$department['name']"
    :breadcrumbs="[
        ['title' => 'Dashboard', 'href' => '/dashboard'],
        ['title' => 'Departments', 'href' => '/admin/departments'],
        ['title' => 'Edit'],
    ]"
>
    <div class="{{ \App\Admin\Ui::FORM_PAGE }}">
        <x-admin.page-header
            :title="'Edit '.$department['name']"
            description="Update the department description, logo and hero content."
            backHref="/admin/departments"
        />
        <x-island name="admin/departments/DepartmentForm" :props="['department' => $department]" />
    </div>
</x-layouts.app>
