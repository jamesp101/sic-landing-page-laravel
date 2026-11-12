@php
    use App\Site\Seo;
    use App\Support\Format;

    $hasEventDates = $post['type'] === 'event' && $post['startDate'];
@endphp
<x-layouts.public :seo="$seo" :layout="$layout">
    <article class="py-12 md:py-20">
        <div class="reading-frame">
            <header class="space-y-4">
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <x-site.type-pill :type="$post['type']" />
                    @if ($post['department'])
                        <a href="{{ \App\Site\SiteNav::departmentHref($post['department']['slug']) }}" class="{{ \App\Site\Classes::TEXT_LINK }}">
                            {{ $post['department']['name'] }}
                        </a>
                    @endif
                </div>
                <h1 class="text-balance text-3xl font-bold leading-tight text-brand-800 md:text-5xl dark:text-foreground">
                    {{ $post['title'] }}
                </h1>
                <p class="flex flex-wrap items-center gap-x-6 gap-y-1 border-y border-border py-4 text-sm text-muted-foreground">
                    @if ($post['authorName'])
                        <span>By {{ $post['authorName'] }}</span>
                    @endif
                    <span>
                        Published <time datetime="{{ Seo::iso($post['createdAt']) }}">{{ Format::formatDate($post['createdAt']) }}</time>
                    </span>
                    @if (($post['updatedAt']->getTimestamp() - $post['createdAt']->getTimestamp()) * 1000 > 86_400_000)
                        <span>
                            Updated <time datetime="{{ Seo::iso($post['updatedAt']) }}">{{ Format::formatDate($post['updatedAt']) }}</time>
                        </span>
                    @endif
                </p>
            </header>

            @if ($hasEventDates)
                <section aria-labelledby="event-details" class="mt-8 rounded-2xl border border-border bg-status-event-bg p-6 text-status-event-fg">
                    <h2 id="event-details" class="font-sans text-base font-semibold">
                        Event details
                    </h2>
                    <p class="mt-2 text-sm">
                        <span class="font-semibold">When:</span>
                        <time datetime="{{ Seo::iso($post['startDate']) }}">
                            {{ Format::formatDateRange($post['startDate'], $post['endDate'], ['time' => true]) }}
                        </time>
                    </p>
                </section>
            @endif

            @if ($post['imageUrl'])
                <img
                    src="{{ $post['imageUrl'] }}"
                    @if ($post['imageSmallUrl'])
                        srcset="{{ $post['imageSmallUrl'] }} 640w, {{ $post['imageUrl'] }} 1600w"
                        sizes="(min-width: 768px) 720px, 100vw"
                    @endif
                    alt=""
                    width="1600"
                    height="900"
                    fetchpriority="high"
                    decoding="async"
                    class="mt-8 aspect-video w-full rounded-2xl object-cover"
                />
            @endif

            <div class="mt-10 text-base leading-relaxed text-foreground">{!! $post['html'] !!}</div>

            <p class="mt-12 border-t border-border pt-6 print:hidden">
                <a href="/posts" class="{{ \App\Site\Classes::TEXT_LINK }}">
                    &larr; Back to News &amp; Events
                </a>
            </p>
        </div>
    </article>
    @if (\App\Content\Content::hasAlbum($post['html']))
        <x-site.album-lightbox />
    @endif

    @if (count($related) > 0)
        <section class="border-t border-border bg-surface py-16 print:hidden md:py-24" aria-labelledby="related-heading">
            <div class="page-frame">
                <h2 id="related-heading" class="text-3xl font-bold text-brand-800 md:text-4xl dark:text-foreground">
                    Related posts
                </h2>
                <div class="mt-10 grid grid-cols-1 gap-8 md:grid-cols-3">
                    @foreach ($related as $item)
                        <x-site.post-card :post="$item" variant="compact" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</x-layouts.public>
