@props(['eyebrow' => null, 'title', 'lead' => null])
<header class="border-b border-border bg-linear-to-b from-brand-100 to-background py-12 dark:from-surface-alt dark:to-background">
    <div class="page-frame">
        @if ($eyebrow)
            <x-site.eyebrow>{{ $eyebrow }}</x-site.eyebrow>
        @endif
        <h1 class="mt-3 text-4xl font-bold text-brand-800 md:text-5xl dark:text-foreground">{{ $title }}</h1>
        @if ($lead)
            <p class="mt-4 max-w-2xl text-base leading-relaxed text-muted-foreground md:text-lg">{{ $lead }}</p>
        @endif
        {{ $slot }}
    </div>
</header>
