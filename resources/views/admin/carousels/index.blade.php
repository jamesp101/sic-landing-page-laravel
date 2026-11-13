<x-layouts.app title="Carousel Designer" :breadcrumbs="[['title' => 'Dashboard', 'href' => '/dashboard'], ['title' => 'Carousels']]">
    <x-island
        name="admin/carousels/CarouselDesigner"
        :props="[
            'slides' => $slides,
            'departmentField' => $departmentField,
            'initialId' => $initialId,
            'scopeName' => $scopeName,
        ]"
    />
</x-layouts.app>
