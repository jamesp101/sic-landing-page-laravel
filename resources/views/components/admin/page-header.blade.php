{{-- Page title row. Props: title, description, backHref, scoped (show the admin scope indicator). Slot: actions. --}}
@props(['title', 'description' => null, 'backHref' => null, 'scoped' => false])
<header class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
    <div class="flex items-center gap-4">
        @if ($backHref)
            <a
                href="{{ $backHref }}"
                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border-strong bg-surface text-muted-foreground transition-colors hover:bg-surface-alt"
                aria-label="Back"
            >
                <svg
                    class="h-4 w-4"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="m12 19-7-7 7-7" />
                    <path d="M19 12H5" />
                </svg>
            </a>
        @endif
        <div>
            <h1 class="font-serif text-2xl font-bold tracking-tight text-foreground">{{ $title }}</h1>
            @if ($description)
                <p class="mt-1 text-sm text-muted-foreground">{{ $description }}</p>
            @endif
            @if ($scoped)
                <div class="mt-1"><x-admin.scope-indicator /></div>
            @endif
        </div>
    </div>
    <div class="flex items-center gap-2 self-start md:self-auto">
        {{ $slot }}
    </div>
</header>
