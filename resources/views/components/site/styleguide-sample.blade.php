{{-- Every token, type role and public component with fixture data (no DB). Local-only styleguide content.
     `interactive`: interactive islands use fixed ids, so render them in one panel only. --}}
@props(['interactive' => false])
@php
    use App\Site\Classes;

    $now = new \Carbon\CarbonImmutable('2026-03-05T08:00:00+08:00');
    $dept = [
        'id' => 1,
        'name' => 'College of Teacher Education',
        'slug' => 'teacher-education',
        'description' => 'Sample department summary text used to preview the card layout.',
        'logoUrl' => null,
        'updatedAt' => $now,
    ];
    $posts = array_map(fn (string $type, int $i) => [
        'id' => $i + 1,
        'title' => "Sample {$type} headline that may wrap onto a second line",
        'slug' => "sample-{$type}",
        'type' => $type,
        'excerpt' => 'Sample excerpt text. It is plain text, clamped to three lines inside the card.',
        'imageUrl' => null,
        'imageSmallUrl' => null,
        'startDate' => $type === 'event' ? $now : null,
        'endDate' => $type === 'event' ? new \Carbon\CarbonImmutable('2026-03-07T17:00:00+08:00') : null,
        'isFeatured' => false,
        'createdAt' => $now,
        'updatedAt' => $now,
        'department' => $type === 'article' ? null : ['id' => $dept['id'], 'name' => $dept['name'], 'slug' => $dept['slug']],
    ], ['article', 'event', 'announcement'], [0, 1, 2]);
    $slides = [
        ['id' => 1, 'imageUrl' => '/sic-seal.png', 'imageMediumUrl' => null, 'stage' => \App\Carousel\Layout::stage(\App\Carousel\Overlay::legacyLayers(['title' => 'Sample slide one', 'subtitle' => 'Subtitle text', 'linkUrl' => '/posts'])), 'focus' => \App\Carousel\OverlayMobile::slideFocus(null), 'video' => null],
        ['id' => 2, 'imageUrl' => '/sic-seal.png', 'imageMediumUrl' => null, 'stage' => \App\Carousel\Layout::stage(\App\Carousel\Overlay::legacyLayers(['title' => 'Sample slide two'])), 'focus' => \App\Carousel\OverlayMobile::slideFocus(null), 'video' => null],
    ];
    $members = [[
        'id' => 1, 'name' => 'Sample Dean', 'role' => 'Dean', 'imageUrl' => null,
        'children' => [
            ['id' => 2, 'name' => 'Sample Chair One', 'role' => 'Program chair', 'imageUrl' => null, 'children' => [
                ['id' => 4, 'name' => 'Sample Instructor', 'role' => 'Instructor', 'imageUrl' => null, 'children' => []],
            ]],
            ['id' => 3, 'name' => 'Sample Chair Two', 'role' => 'Program chair', 'imageUrl' => null, 'children' => []],
        ],
    ]];
    $kinds = ['pdf', 'doc', 'sheet', 'image', 'archive', 'other'];
    $downloads = array_map(fn (string $kind, int $i) => [
        'id' => $i + 1,
        'fileName' => 'sample-file-'.($i + 1).'.'.($kind === 'other' ? 'bin' : $kind),
        'url' => '#',
        'downloadUrl' => '#',
        'size' => 1024 * 150 * ($i + 1),
        'extension' => $kind === 'other' ? 'bin' : $kind,
        'kind' => $kind,
        'tags' => $i % 2 ? ['forms'] : [],
        'directory' => $i < 3 ? 'Forms' : 'Schedules',
        'createdAt' => $now,
        'department' => $i === 0 ? ['id' => $dept['id'], 'name' => $dept['name'], 'slug' => $dept['slug']] : null,
    ], $kinds, array_keys($kinds));
    $directories = array_values(array_unique(array_column($downloads, 'directory')));
    $groups = array_map(fn (string $name) => ['name' => $name, 'files' => array_values(array_filter($downloads, fn (array $f) => $f['directory'] === $name))], $directories);

    $swatches = [
        ['brand-950', 'bg-brand-950'], ['brand-900', 'bg-brand-900'], ['brand-800', 'bg-brand-800'], ['brand-700', 'bg-brand-700'],
        ['brand-600', 'bg-brand-600'], ['brand-500', 'bg-brand-500'], ['brand-400', 'bg-brand-400'], ['brand-200', 'bg-brand-200'],
        ['brand-100', 'bg-brand-100'], ['brand-50', 'bg-brand-50'], ['gold-300', 'bg-gold-300'], ['gold-400', 'bg-gold-400'],
        ['gold-500', 'bg-gold-500'], ['gold-600', 'bg-gold-600'], ['background', 'bg-background'], ['surface', 'bg-surface'],
        ['surface-alt', 'bg-surface-alt'], ['foreground', 'bg-foreground'], ['muted-foreground', 'bg-muted-foreground'],
        ['primary', 'bg-primary'], ['link', 'bg-link'], ['border-strong', 'bg-border-strong'], ['destructive', 'bg-destructive'],
        ['article', 'bg-status-article-bg'], ['event', 'bg-status-event-bg'], ['announcement', 'bg-status-announcement-bg'],
        ['pdf', 'bg-file-pdf-bg'], ['doc', 'bg-file-doc-bg'], ['sheet', 'bg-file-sheet-bg'], ['image', 'bg-file-image-bg'],
        ['archive', 'bg-file-archive-bg'], ['other', 'bg-file-other-bg'],
    ];
    $shadows = ['shadow-brand-sm', 'shadow-brand-md', 'shadow-brand-lg', 'shadow-brand-xl'];
    $markdown = "# Markdown section\n\nBody text with **bold** copy, shifted one heading level down.\n\n## Sub heading\n\n- First point\n- Second point";
    $h = 'mb-6 mt-16 border-b border-border pb-2 font-sans text-xl font-semibold text-foreground';
@endphp
<div class="page-frame pb-16">
    <h3 class="{{ $h }} mt-8!">Colour tokens</h3>
    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
        @foreach ($swatches as [$name, $cls])
            <li class="text-xs">
                <span @class(['block h-14 rounded-lg border border-border-strong', $cls])></span>
                <span class="mt-1 block text-muted-foreground">{{ $name }}</span>
            </li>
        @endforeach
    </ul>
    <ul class="mt-6 flex flex-wrap gap-6">
        @foreach ($shadows as $cls)
            <li @class(['flex h-16 w-32 items-center justify-center rounded-xl bg-surface text-xs text-muted-foreground', $cls])>
                {{ $cls }}
            </li>
        @endforeach
    </ul>

    <h3 class="{{ $h }}">Type roles</h3>
    <div class="space-y-4">
        <p class="font-serif text-4xl font-bold tracking-tight md:text-6xl">Hero title — Playfair Display</p>
        <p class="font-serif text-4xl font-bold md:text-5xl">Page title (h1)</p>
        <p class="font-serif text-3xl font-bold md:text-4xl">Section title (h2)</p>
        <p class="font-sans text-lg font-semibold">Card title (h3) — Inter</p>
        <p class="font-sans text-base leading-relaxed">Body text in Inter. San Isidro College, Malaybalay City, Bukidnon.</p>
        <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Meta / label</p>
        <x-site.eyebrow>Eyebrow label</x-site.eyebrow>
        <div class="bg-brand-900 p-4">
            <x-site.eyebrow tone="onDark">Eyebrow on dark</x-site.eyebrow>
        </div>
    </div>

    <h3 class="{{ $h }}">Buttons</h3>
    <div class="flex flex-wrap items-center gap-4">
        <a href="#top" class="{{ Classes::BTN_GOLD }}">Gold</a>
        <a href="#top" class="{{ Classes::BTN_PRIMARY }}">Primary</a>
        <a href="#top" class="{{ Classes::BTN_OUTLINE }}">Outline</a>
        <span class="rounded-xl bg-brand-900 p-3">
            <a href="#top" class="{{ Classes::BTN_ON_DARK }}">On dark</a>
        </span>
    </div>

    <h3 class="{{ $h }}">Pills and badges</h3>
    <div class="flex flex-wrap items-center gap-3">
        @foreach (['article', 'event', 'announcement'] as $t)
            <x-site.type-pill :type="$t" />
        @endforeach
        @foreach ($kinds as $kind)
            <x-site.file-kind-badge :kind="$kind" :extension="$kind === 'other' ? 'bin' : $kind" />
        @endforeach
    </div>

    <h3 class="{{ $h }}">Post cards (default · compact)</h3>
    <div class="grid grid-cols-1 gap-8 md:grid-cols-3">
        @foreach ($posts as $post)
            <x-site.post-card :post="$post" />
        @endforeach
    </div>
    <div class="mt-8 grid grid-cols-1 gap-8 md:grid-cols-3">
        @foreach ($posts as $post)
            <x-site.post-card :post="$post" variant="compact" />
        @endforeach
    </div>

    <h3 class="{{ $h }}">Department card</h3>
    <div class="grid grid-cols-1 gap-8 md:grid-cols-3">
        <x-site.department-card :department="$dept" />
    </div>

    <h3 class="{{ $h }}">Org chart</h3>
    <x-site.org-chart :nodes="$members" />

    <h3 class="{{ $h }}">Empty state</h3>
    <x-site.empty-state title="No posts found" description="No posts match your search criteria." actionHref="#top" actionLabel="Show all posts" />

    <h3 class="{{ $h }}">Pagination</h3>
    <x-site.pagination :page="6" :pageCount="10" :href="fn (int $page) => '#page-'.$page" label="Sample pages" />

    <h3 class="{{ $h }}">Contact info</h3>
    <div class="max-w-xl">
        <x-site.contact-info-card />
    </div>

    <h3 class="{{ $h }}">Downloads</h3>
    <x-site.download-browser
        :downloads="$downloads"
        :groups="$groups"
        :folders="[['name' => 'Forms', 'count' => 3, 'href' => '#forms'], ['name' => 'Schedules', 'count' => 3, 'href' => '#schedules']]"
        selected=""
        allHref="#downloads"
        :totalCount="count($downloads)"
    />

    @if ($interactive)
        <h3 class="{{ $h }}">Filter bar</h3>
        <x-site.filter-bar action="/styleguide" search="" :departments="[$dept]" sort="newest" clearHref="/styleguide" />
        <h3 class="{{ $h }}">Contact form</h3>
        <div class="max-w-xl">
            <x-island name="site/ContactForm" :props="['action' => '/contact']" />
        </div>
    @endif
</div>

<h3 class="page-frame font-sans text-xl font-semibold text-foreground">Banner, hero and carousel</h3>
<div class="mt-6">
    <x-site.page-banner eyebrow="Eyebrow" title="Page banner title" lead="Lead text sits under the title and wraps at a readable width." />
    <x-site.markdown-section :content="$markdown" />
    <x-site.brand-hero />
    @if ($interactive)
        <x-island name="site/HeroCarousel" :props="['slides' => $slides, 'label' => 'Sample slideshow', 'tall' => false]" />
    @endif
</div>
