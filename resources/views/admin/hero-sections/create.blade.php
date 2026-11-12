<x-layouts.app
    title="New hero section"
    :breadcrumbs="[
        ['title' => 'Dashboard', 'href' => '/dashboard'],
        ['title' => 'Hero Sections', 'href' => '/admin/hero-sections'],
        ['title' => 'New'],
    ]"
>
    <div class="{{ \App\Admin\Ui::WIDE_PAGE }}">
        <x-admin.page-header
            title="New hero section"
            description="Design a new hero panel for the Main site or a department page with the visual editor."
            backHref="/admin/hero-sections"
        />
        <x-island
            name="admin/hero-sections/HeroSectionForm"
            :props="['departments' => $departments, 'isSuperAdmin' => $isSuperAdmin, 'defaultDepartmentId' => $defaultDepartmentId]"
        />
    </div>
</x-layouts.app>
