<x-layouts.public :seo="$seo" :layout="$layout">
    @if (count($carousel) > 0)
        <x-island name="site/HeroCarousel" class="contents" :props="['slides' => $carousel, 'tall' => false, 'label' => $department['name'].' highlights']">
            <x-site.hero-carousel-fallback :slides="$carousel" :tall="false" :label="$department['name'].' highlights'" />
        </x-island>
    @endif

    <x-site.page-banner eyebrow="Department" :title="$department['name']" :lead="$department['description']" />

    <x-site.markdown-section :content="$department['heroContent']" />
    @foreach ($heroSections as $section)
        <x-site.markdown-section :content="$section['content']" />
    @endforeach

    @if (count($members) > 0)
        <section id="org-chart" class="scroll-mt-24 border-b border-border bg-surface-alt py-16 md:py-24" aria-labelledby="people-heading">
            <div class="page-frame">
                <div class="text-center">
                    <x-site.eyebrow>People</x-site.eyebrow>
                    <h2 id="people-heading" class="mt-3 text-3xl font-bold text-brand-800 md:text-4xl dark:text-foreground">
                        Leadership &amp; faculty
                    </h2>
                </div>
                <div class="mt-12 overflow-x-auto pb-4" role="region" aria-labelledby="people-heading" tabindex="0">
                    <div class="mx-auto w-max min-w-full">
                        <x-site.org-chart :nodes="$members" />
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if (count($posts) > 0)
        <section class="py-16 md:py-24" aria-labelledby="dept-posts-heading">
            <div class="page-frame">
                <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <h2 id="dept-posts-heading" class="text-3xl font-bold text-brand-800 md:text-4xl dark:text-foreground">
                        Announcements &amp; events
                    </h2>
                    <a href="{{ \App\Site\PostFilters::postsHref(['departmentId' => $department['id']]) }}" class="{{ \App\Site\Classes::TEXT_LINK }}">
                        View all {{ $postCount }} from this department &rarr;
                    </a>
                </div>
                <div class="mt-10 grid grid-cols-1 gap-8 md:grid-cols-3">
                    @foreach ($posts as $post)
                        <x-site.post-card :post="$post" variant="compact" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</x-layouts.public>
