<x-layouts.app
    title="Edit hero section"
    :breadcrumbs="[
        ['title' => 'Dashboard', 'href' => '/dashboard'],
        ['title' => 'Hero Sections', 'href' => '/admin/hero-sections'],
        ['title' => 'Edit'],
    ]"
>
    <div class="{{ \App\Admin\Ui::WIDE_PAGE }}">
        <x-admin.page-header
            title="Edit hero section"
            :description="'Make changes to “'.($section->title === null || $section->title === '' ? 'Hero section' : $section->title).'”.'"
            backHref="/admin/hero-sections"
        />
        <x-island
            name="admin/hero-sections/HeroSectionForm"
            :props="['departments' => $departments, 'isSuperAdmin' => $isSuperAdmin, 'section' => $values]"
        />
    </div>
</x-layouts.app>
