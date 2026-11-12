@php($site = \App\Support\Site::SITE)
<x-layouts.public :seo="$seo" :layout="$layout">
    @if (count($carousel) > 0)
        <x-island name="site/HeroCarousel" class="contents" :props="['slides' => $carousel, 'pageTitle' => $site['name'].' — '.$site['motto']]">
            <x-site.hero-carousel-fallback :slides="$carousel" :pageTitle="$site['name'].' — '.$site['motto']" />
        </x-island>
    @else
        <x-site.brand-hero />
    @endif

    <x-site.markdown-section :content="$welcomeHero" />
    @foreach ($heroSections as $section)
        <x-site.markdown-section :content="$section['content']" />
    @endforeach

    <section id="departments" class="scroll-mt-20 py-16 md:py-24" aria-labelledby="programs-heading">
        <div class="page-frame">
            <div class="max-w-2xl">
                <x-site.eyebrow>Academic programs</x-site.eyebrow>
                <h2 id="programs-heading" class="mt-3 text-3xl font-bold text-brand-800 md:text-4xl dark:text-foreground">
                    Our departments
                </h2>
                <p class="mt-4 leading-relaxed text-muted-foreground">
                    Explore the academic departments of {{ $site['name'] }}, their programs, announcements and people.
                </p>
            </div>
            @if (count($layout['departments']) > 0)
                <div class="mt-12 grid grid-cols-1 gap-8 md:grid-cols-3">
                    @foreach ($layout['departments'] as $department)
                        <x-site.department-card :department="$department" />
                    @endforeach
                </div>
            @else
                <div class="mt-12">
                    <x-site.empty-state title="Departments will appear here soon." />
                </div>
            @endif
        </div>
    </section>

    <section class="border-t border-border bg-surface py-16 md:py-24" aria-labelledby="latest-heading">
        <div class="page-frame">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                <div>
                    <x-site.eyebrow>News &amp; events</x-site.eyebrow>
                    <h2 id="latest-heading" class="mt-3 text-3xl font-bold text-brand-800 md:text-4xl dark:text-foreground">
                        Latest from San Isidro
                    </h2>
                    <p class="mt-3 text-muted-foreground">News, campus events and announcements.</p>
                </div>
                <a href="/posts" class="{{ \App\Site\Classes::TEXT_LINK }}">
                    View all posts &rarr;
                </a>
            </div>
            @if (count($latestPosts) > 0)
                <div class="mt-12 grid grid-cols-1 gap-8 md:grid-cols-3">
                    @foreach ($latestPosts as $post)
                        <x-site.post-card :post="$post" />
                    @endforeach
                </div>
            @else
                <div class="mt-12">
                    <x-site.empty-state title="No posts yet." description="Check back soon for news and announcements." />
                </div>
            @endif
        </div>
    </section>
</x-layouts.public>
