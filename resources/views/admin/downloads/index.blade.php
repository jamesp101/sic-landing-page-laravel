<x-layouts.app title="Downloads" :breadcrumbs="[['title' => 'Dashboard', 'href' => '/dashboard'], ['title' => 'Downloads']]">
    <div class="{{ \App\Admin\Ui::WIDE_PAGE }}">
        <x-admin.page-header
            :scoped="true"
            title="Downloads"
            description="Upload and organize forms, manuals and resources for the public downloads page."
        />
        <x-island
            name="admin/downloads/DownloadsManager"
            :props="[
                'downloads' => $downloads,
                'departments' => $departments,
                'manager' => $manager,
                'isSuperAdmin' => $superadmin,
            ]"
        />
        <x-admin.list-pagination :list="$list" />
    </div>
</x-layouts.app>
