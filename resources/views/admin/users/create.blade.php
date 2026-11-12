<x-layouts.app
    title="New user"
    :breadcrumbs="[
        ['title' => 'Dashboard', 'href' => '/dashboard'],
        ['title' => 'Users', 'href' => '/admin/users'],
        ['title' => 'New user'],
    ]"
>
    <div class="{{ \App\Admin\Ui::FORM_PAGE }}">
        <x-admin.page-header
            title="New user"
            description="Add a person who signs in to the admin and set their role."
            backHref="/admin/users"
        />
        <x-island name="admin/users/UserForm" :props="['departments' => $departments]" />
    </div>
</x-layouts.app>
