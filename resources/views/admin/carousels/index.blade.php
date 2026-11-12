<x-layouts.app title="Carousel Designer" :breadcrumbs="[['title' => 'Dashboard', 'href' => '/dashboard'], ['title' => 'Carousels']]">
    <x-island
        name="admin/carousels/CarouselDesigner"
        :props="[
            'slides' => $slides,
            'departments' => $departments,
            'initialId' => $initialId,
            'scopeName' => $scopeName,
            'defaultDepartmentId' => $defaultDepartmentId,
            'superadmin' => $superadmin,
            'lockedDepartmentId' => $lockedDepartmentId,
        ]"
    />
</x-layouts.app>
