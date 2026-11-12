{{-- `href` is a closure `fn (int $page): string` keeping the list's other query parameters. --}}
@props(['page', 'pageCount', 'href', 'label' => 'Pagination'])
@php
    $item = 'inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold transition-colors duration-200 ease-out md:min-h-10 md:min-w-10';
    $link = "{$item} text-muted-foreground hover:bg-surface hover:text-foreground";
@endphp
@if ($pageCount > 1)
    <nav aria-label="{{ $label }}" class="mt-12 flex justify-center">
        <ul class="flex flex-wrap items-center justify-center gap-2 md:gap-1">
            @if ($page > 1)
                <li>
                    <a href="{{ $href($page - 1) }}" rel="prev" class="{{ $link }}">
                        <span aria-hidden="true">←</span>&nbsp;Previous
                    </a>
                </li>
            @endif
            @foreach (\App\Support\Pagination::pageWindow($page, $pageCount) as $entry)
                @if ($entry === null)
                    <li aria-hidden="true" class="{{ $item }} text-muted-foreground">
                        …
                    </li>
                @else
                    <li>
                        <a
                            href="{{ $href($entry) }}"
                            @if ($entry === $page) aria-current="page" @endif
                            aria-label="Page {{ $entry }}"
                            class="{{ $entry === $page ? "{$item} bg-primary text-primary-foreground" : $link }}"
                        >
                            {{ $entry }}
                        </a>
                    </li>
                @endif
            @endforeach
            @if ($page < $pageCount)
                <li>
                    <a href="{{ $href($page + 1) }}" rel="next" class="{{ $link }}">
                        Next&nbsp;<span aria-hidden="true">→</span>
                    </a>
                </li>
            @endif
        </ul>
    </nav>
@endif
