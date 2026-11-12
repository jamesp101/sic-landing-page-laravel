{{-- Same container as the header and banner, so the section's left edge lines up with them. Text gets its reading
     measure per element (app.css: `[data-sic-blocks]` for block documents, `[data-sic-markdown]` for Legacy Markdown;
     both markers come from the renderers); grids, cards and accordions use the full width. `title`: optional h2. --}}
@props(['content' => null, 'title' => null])
@php
    $html = \App\Content\Content::parseContent($content)->html();
@endphp
@if ($html)
    <section data-content-section {{ $attributes->class(['border-b border-border bg-surface py-16 md:py-24']) }}>
        <div class="page-frame">
            @if ($title)
                <h2 class="mb-6 text-3xl font-bold text-brand-800 md:text-4xl dark:text-foreground">{{ $title }}</h2>
            @endif
            <div class="text-base leading-relaxed text-foreground">{!! $html !!}</div>
        </div>
    </section>
    @if (\App\Content\Content::hasAlbum($html))
        <x-site.album-lightbox />
    @endif
@endif
