@php
    $posts = $result['items'];
    $type = $filters['type'];
    $filtered = \App\Site\PostFilters::hasPostFilters($filters);
    $first = ($page - 1) * $perPage + 1;
    $tabs = [
        ['label' => 'All posts', 'type' => null],
        ['label' => 'Articles', 'type' => 'article'],
        ['label' => 'Events', 'type' => 'event'],
        ['label' => 'Announcements', 'type' => 'announcement'],
    ];
@endphp
<x-layouts.public :seo="$seo" :layout="$layout">
    <x-site.page-banner
        eyebrow="News & events"
        title="News, Events & Announcements"
        lead="Keep up to date with the latest stories and official advisories from our campus."
    />

    <section class="py-12 md:py-16" aria-label="Posts">
        <div class="page-frame">
            <nav aria-label="Post type" class="mb-6">
                <ul class="flex flex-wrap gap-2">
                    @foreach ($tabs as $tab)
                        @php($current = $tab['type'] === $type)
                        <li>
                            <a
                                href="{{ \App\Site\PostFilters::postsHref([...$filters, 'type' => $tab['type']]) }}"
                                @if ($current) aria-current="page" @endif
                                @class([
                                    'inline-flex min-h-11 items-center rounded-lg px-4 py-2 text-sm font-semibold transition-colors duration-200 ease-out md:min-h-10',
                                    'bg-primary text-primary-foreground' => $current,
                                    'text-muted-foreground hover:bg-surface hover:text-foreground' => ! $current,
                                ])
                            >
                                {{ $tab['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <x-site.filter-bar
                action="/posts"
                :search="$filters['search'] ?? ''"
                searchLabel="Search posts"
                searchPlaceholder="Search stories…"
                :departmentId="$filters['departmentId']"
                :departments="$layout['departments']"
                :sort="$filters['sort']"
                :hidden="$type ? ['type' => $type] : []"
                :active="$filtered"
                clearHref="/posts"
            />

            <p class="mt-6 text-sm text-muted-foreground" role="status">
                {{ $result['pageCount'] > 1
                    ? "Showing {$first}–".($first + count($posts) - 1)." of {$result['total']} posts"
                    : "{$result['total']} ".($result['total'] === 1 ? 'post' : 'posts') }}{{ $filtered ? ' matching your filters' : '' }}.
            </p>

            @if (count($posts) > 0)
                <div class="mt-6 grid grid-cols-1 gap-8 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($posts as $post)
                        <x-site.post-card :post="$post" headingLevel="h2" />
                    @endforeach
                </div>
            @else
                <div class="mt-6">
                    <x-site.empty-state title="No posts found" description="No posts match your search criteria." actionHref="/posts" actionLabel="Show all posts" />
                </div>
            @endif

            <x-site.pagination
                :page="$page"
                :pageCount="$result['pageCount']"
                :href="fn (int $target) => \App\Site\PostFilters::postsHref($filters, $target)"
                label="Posts pages"
            />
        </div>
    </section>
</x-layouts.public>
