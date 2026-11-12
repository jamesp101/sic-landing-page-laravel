<x-layouts.app
    :title="'Edit '.$user['name']"
    :breadcrumbs="[
        ['title' => 'Dashboard', 'href' => '/dashboard'],
        ['title' => 'Users', 'href' => '/admin/users'],
        ['title' => 'Edit user'],
    ]"
>
    <div class="{{ \App\Admin\Ui::FORM_PAGE }}">
        <x-admin.page-header title="Edit user" :description="'Change the details of '.$user['name'].'.'" backHref="/admin/users" />
        <x-island name="admin/users/UserForm" :props="['user' => $user, 'departments' => $departments]" />
    </div>
</x-layouts.app>
