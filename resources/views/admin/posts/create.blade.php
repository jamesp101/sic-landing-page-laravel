<x-layouts.app
    title="New post"
    :breadcrumbs="[
        ['title' => 'Dashboard', 'href' => '/dashboard'],
        ['title' => 'Posts', 'href' => '/admin/posts'],
        ['title' => 'New'],
    ]"
>
    <div class="{{ \App\Admin\Ui::WIDE_PAGE }}">
        <x-admin.page-header
            title="New post"
            description="Publish a new article, event advisory or announcement."
            backHref="/admin/posts"
        />
        <x-island
            name="admin/posts/PostForm"
            :props="['departments' => $departments, 'isSuperAdmin' => $isSuperAdmin, 'defaultDepartmentId' => $defaultDepartmentId]"
        />
    </div>
</x-layouts.app>
