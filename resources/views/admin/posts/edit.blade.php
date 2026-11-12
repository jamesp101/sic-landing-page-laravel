<x-layouts.app
    title="Edit post"
    :breadcrumbs="[
        ['title' => 'Dashboard', 'href' => '/dashboard'],
        ['title' => 'Posts', 'href' => '/admin/posts'],
        ['title' => 'Edit'],
    ]"
>
    <div class="{{ \App\Admin\Ui::WIDE_PAGE }}">
        <x-admin.page-header title="Edit post" :description="'Make changes to “'.$post->title.'”.'" backHref="/admin/posts" />
        <x-island
            name="admin/posts/PostForm"
            :props="['departments' => $departments, 'isSuperAdmin' => $isSuperAdmin, 'post' => $values]"
        />
    </div>
</x-layouts.app>
