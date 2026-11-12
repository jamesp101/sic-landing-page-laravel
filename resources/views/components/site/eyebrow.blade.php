@props(['tone' => 'default'])
<p {{ $attributes->class([
    "inline-flex items-center gap-3 text-xs font-semibold uppercase tracking-[0.25em] before:h-0.5 before:w-8 before:rounded-sm before:bg-gold-500 before:content-['']",
    $tone === 'onDark' ? 'text-gold-300' : 'text-link',
]) }}>
    {{ $slot }}
</p>
