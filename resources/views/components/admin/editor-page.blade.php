{{--
    Shell of a Rich content editor page: layout, breadcrumbs, page header and the form island.
    Props: title (layout and header), description, island (island name), props (island props),
    section (['title', 'href'] of the list page, also the back link; omit for a top-level page), crumb (last breadcrumb).
--}}
@props(['title', 'description', 'island', 'props' => [], 'section' => null, 'crumb'])
<x-layouts.app
    :title="$title"
    :breadcrumbs="[
        ['title' => 'Dashboard', 'href' => '/dashboard'],
        ...($section ? [['title' => $section['title'], 'href' => $section['href']]] : []),
        ['title' => $crumb],
    ]"
>
    <div class="{{ \App\Admin\Ui::EDITOR_PAGE }}">
        <x-admin.page-header :title="$title" :description="$description" :backHref="$section['href'] ?? null" />
        <x-island :name="$island" :props="$props" />
    </div>
</x-layouts.app>
