<x-layouts.app
    title="Landing page settings"
    :breadcrumbs="[['title' => 'Dashboard', 'href' => '/dashboard'], ['title' => 'Landing settings']]"
>
    <div class="{{ \App\Admin\Ui::EDITOR_PAGE }}">
        <x-admin.page-header
            title="Landing page settings"
            description="Design the main welcome page hero section with the visual editor."
        />
        <x-island name="admin/settings/LandingSettingsForm" :props="['welcomeHeroContent' => $welcomeHero]" />
    </div>
</x-layouts.app>
