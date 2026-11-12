{{-- `variant`: default | compact. `headingLevel`: h2 when the card sits directly under the page h1. `priority`: above the fold (eager + high priority). --}}
@props(['post', 'variant' => 'default', 'headingLevel' => 'h3', 'priority' => false])
@php
    $compact = $variant === 'compact';
    $href = \App\Site\SiteNav::postHref($post['slug']);
    // Cards sit in a 1-, 2- or 3-column grid (see the pages that list them): about 400 px wide on desktop.
    $imageSizes = '(min-width: 1024px) 400px, (min-width: 640px) 50vw, 100vw';
    $showRange = $post['type'] === 'event' && $post['startDate'];
@endphp
<article @class([
    \App\Site\Classes::CARD,
    'group relative flex flex-col overflow-hidden has-[a:focus-visible]:ring-2 has-[a:focus-visible]:ring-ring has-[a:focus-visible]:ring-offset-2 has-[a:focus-visible]:ring-offset-background',
])>
    @if ($post['imageUrl'])
        <img
            src="{{ $post['imageUrl'] }}"
            @if ($post['imageSmallUrl'])
                srcset="{{ $post['imageSmallUrl'] }} 640w, {{ $post['imageUrl'] }} 1600w"
                sizes="{{ $imageSizes }}"
            @endif
            alt=""
            width="800"
            height="450"
            loading="{{ $priority ? 'eager' : 'lazy' }}"
            @if ($priority) fetchpriority="high" @endif
            decoding="async"
            class="aspect-video w-full object-cover"
        />
    @else
        <div class="flex aspect-video w-full items-center justify-center bg-brand-100 dark:bg-surface-alt">
            <img
                src="/sic-seal-96.webp"
                srcset="/sic-seal-96.webp 96w, /sic-seal.webp 224w"
                sizes="64px"
                alt=""
                width="64"
                height="64"
                loading="lazy"
                decoding="async"
                class="h-16 w-16 opacity-60"
            />
        </div>
    @endif
    <div @class(['flex flex-1 flex-col', $compact ? 'p-5' : 'p-6'])>
        <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            <x-site.type-pill :type="$post['type']" />
            @if ($post['department'])
                <span>{{ $post['department']['name'] }}</span>
            @endif
        </div>
        <{{ $headingLevel }} @class(['line-clamp-3 font-sans font-semibold leading-snug text-foreground', $compact ? 'mt-2 text-base' : 'mt-3 text-lg'])>
            <a
                href="{{ $href }}"
                class="rounded outline-none after:absolute after:inset-0 after:content-[''] focus-visible:ring-0 focus-visible:ring-offset-0 group-hover:text-link"
            >
                {{ $post['title'] }}
            </a>
        </{{ $headingLevel }}>
        <p @class(['text-sm leading-relaxed text-muted-foreground', $compact ? 'mt-2 line-clamp-2' : 'mt-3 line-clamp-3'])>
            {{ $post['excerpt'] }}
        </p>
        @if ($showRange)
            <p class="mt-4 text-xs font-semibold text-link">
                <time datetime="{{ \App\Site\Seo::iso($post['startDate']) }}">{{ \App\Support\Format::formatDateRange($post['startDate'], $post['endDate']) }}</time>
            </p>
        @endif
        <div class="mt-auto pt-5">
            <p class="flex items-center justify-between border-t border-border pt-4 text-xs text-muted-foreground">
                <time datetime="{{ \App\Site\Seo::iso($post['createdAt']) }}">{{ \App\Support\Format::formatDate($post['createdAt']) }}</time>
                <span class="font-semibold text-link" aria-hidden="true">
                    Read more
                    <span class="inline-block transition-transform duration-200 ease-out motion-safe:group-hover:translate-x-0.5">
                        &rarr;
                    </span>
                </span>
            </p>
        </div>
    </div>
</article>
