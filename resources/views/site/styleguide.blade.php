@php
    $seo = ['title' => 'Styleguide', 'description' => 'Design tokens, type roles and public components.', 'noindex' => true];
@endphp
<x-layouts.public :seo="$seo" :minimal="true">
    <x-site.page-banner
        eyebrow="Design system"
        title="Styleguide"
        lead="Tokens, type and components with fixture data. The first panel follows the page theme (toggle it in the header); the second is forced dark."
    />
    <h2 class="page-frame mt-10 text-2xl font-bold">Current theme</h2>
    <x-site.styleguide-sample :interactive="true" />
    <div class="dark bg-background text-foreground">
        <h2 class="page-frame pt-10 text-2xl font-bold">Dark theme</h2>
        <x-site.styleguide-sample />
    </div>
</x-layouts.public>
