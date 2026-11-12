<x-layouts.app title="Navigation links" :breadcrumbs="[['title' => 'Dashboard', 'href' => '/dashboard'], ['title' => 'Navigation links']]">
    <div class="{{ \App\Admin\Ui::WIDE_PAGE }}">
        <x-admin.page-header
            title="Navigation links"
            description="Arrange the links in the main website navigation bar. Drag to reorder or nest; changes go live as you drop."
        />
        <x-island
            name="admin/navlinks/NavLinksEditor"
            :props="['links' => $links, 'departments' => $departments, 'suggestions' => $suggestions, 'knownPaths' => $knownPaths]"
        />
    </div>
</x-layouts.app>
