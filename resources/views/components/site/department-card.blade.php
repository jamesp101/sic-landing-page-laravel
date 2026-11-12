@props(['department'])
@php
    $thumb = \App\Storage\Variants::THUMB_WIDTH;
    $logoMax = 512; // IMAGE_TARGETS.logo.maxWidth
@endphp
<article @class([
    \App\Site\Classes::CARD,
    'group relative flex flex-col p-8 has-[a:focus-visible]:ring-2 has-[a:focus-visible]:ring-ring has-[a:focus-visible]:ring-offset-2 has-[a:focus-visible]:ring-offset-background',
])>
    @if ($department['logoUrl'])
        <img
            src="{{ $department['logoUrl'] }}"
            @if (! empty($department['logoSmallUrl']))
                srcset="{{ $department['logoSmallUrl'] }} {{ $thumb }}w, {{ $department['logoUrl'] }} {{ $logoMax }}w"
                sizes="56px"
            @endif
            alt=""
            width="56"
            height="56"
            loading="lazy"
            decoding="async"
            class="h-14 w-14 rounded-xl object-contain"
        />
    @else
        <span class="flex h-14 w-14 items-center justify-center rounded-xl bg-brand-100 text-lg font-bold text-brand-800 dark:bg-surface-alt dark:text-gold-400" aria-hidden="true">
            {{ \App\Support\Initials::getInitials($department['name']) ?: 'D' }}
        </span>
    @endif
    <h3 class="mt-5 line-clamp-3 font-sans text-xl font-semibold leading-snug text-foreground">
        <a
            href="{{ \App\Site\SiteNav::departmentHref($department['slug']) }}"
            class="rounded outline-none after:absolute after:inset-0 after:content-[''] focus-visible:ring-0 focus-visible:ring-offset-0 group-hover:text-link"
        >
            {{ $department['name'] }}
        </a>
    </h3>
    @if ($department['description'])
        <p class="mt-3 line-clamp-4 text-sm leading-relaxed text-muted-foreground">{{ $department['description'] }}</p>
    @endif
    <div class="mt-auto pt-6">
        <p class="border-t border-border pt-4 text-sm font-semibold text-link" aria-hidden="true">
            View department
            <span class="inline-block transition-transform duration-200 ease-out motion-safe:group-hover:translate-x-0.5">
                &rarr;
            </span>
        </p>
    </div>
</article>
