@php($site = \App\Support\Site::SITE)
<section class="relative overflow-hidden bg-linear-to-br from-brand-950 via-brand-900 to-brand-800 text-white">
    <div class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-gold-500/15 blur-3xl" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -bottom-32 -left-24 h-96 w-96 rounded-full bg-brand-500/20 blur-3xl" aria-hidden="true"></div>
    <div class="relative mx-auto flex w-full max-w-4xl flex-col items-center px-(--frame-gutter) py-20 text-center md:py-28">
        <img
            src="/sic-seal.webp"
            alt="{{ $site['name'] }} seal"
            width="224"
            height="224"
            fetchpriority="high"
            decoding="async"
            class="h-28 w-28 rounded-full border-2 border-gold-500/40 bg-white/5 object-contain md:h-36 md:w-36"
        />
        <x-site.eyebrow tone="onDark" class="mt-8">
            {{ $site['motto'] }}
        </x-site.eyebrow>
        <h1 class="mt-4 animate-fade-in text-4xl font-bold tracking-tight md:text-6xl">{{ $site['name'] }}</h1>
        <p class="mt-5 max-w-2xl text-base leading-relaxed text-white/85 md:text-lg">{{ $site['tagline'] }}</p>
        <div class="mt-8 flex flex-wrap items-center justify-center gap-4">
            <a href="{{ \App\Site\SiteNav::DEPARTMENTS_MENU_URL }}" class="{{ \App\Site\Classes::BTN_GOLD }}">
                Explore programs
            </a>
            <a href="/contact" class="{{ \App\Site\Classes::BTN_ON_DARK }}">
                Contact admissions
            </a>
        </div>
    </div>
</section>
