@props(['crumbs'])
<nav aria-label="Breadcrumb" class="border-b border-border bg-surface">
    <ol class="page-frame flex flex-wrap items-center gap-x-2 gap-y-1 py-1 text-sm text-muted-foreground md:py-3">
        @foreach ($crumbs as $index => $crumb)
            <li class="flex items-center gap-2">
                @if ($index > 0)
                    <span aria-hidden="true">/</span>
                @endif
                @if (! empty($crumb['href']) && $index < count($crumbs) - 1)
                    <a href="{{ $crumb['href'] }}" class="inline-flex min-h-11 items-center rounded text-link underline-offset-4 hover:underline md:min-h-6">
                        {{ $crumb['label'] }}
                    </a>
                @else
                    <span aria-current="page" class="font-medium text-foreground">
                        {{ $crumb['label'] }}
                    </span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
