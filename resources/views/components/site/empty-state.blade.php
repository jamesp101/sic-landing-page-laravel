@props(['title', 'description' => null, 'actionHref' => null, 'actionLabel' => null])
<div class="rounded-2xl border border-dashed border-border-strong bg-surface p-12 text-center">
    <p class="text-base font-semibold text-foreground">{{ $title }}</p>
    @if ($description)
        <p class="mx-auto mt-2 max-w-md text-sm text-muted-foreground">{{ $description }}</p>
    @endif
    @if ($actionHref && $actionLabel)
        <a href="{{ $actionHref }}" class="{{ \App\Site\Classes::BTN_OUTLINE_COMPACT }} mt-6">
            {{ $actionLabel }}
        </a>
    @endif
    {{ $slot }}
</div>
