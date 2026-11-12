{{-- "Showing x–y of N" and the "Pages" nav. Props: `list` (App\Admin\ListState). --}}
@props(['list'])
@php
    $first = ($list->page - 1) * $list->perPage + 1;
    $last = min($list->page * $list->perPage, $list->total);
    $item = 'inline-flex min-w-9 items-center justify-center rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors';
    $link = "{$item} text-muted-foreground hover:bg-surface-alt hover:text-foreground";
@endphp
@if ($list->total > 0)
    <div class="flex flex-col items-center justify-between gap-3 sm:flex-row">
        <p class="text-sm text-muted-foreground">Showing {{ $first }}–{{ $last }} of {{ $list->total }}</p>
        @if ($list->pageCount > 1)
            <nav aria-label="Pages">
                <ul class="flex flex-wrap items-center gap-1">
                    @if ($list->page > 1)
                        <li>
                            <a href="{{ $list->pageHref($list->page - 1) }}" rel="prev" class="{{ $link }}"><span aria-hidden="true">←</span>&nbsp;Previous</a>
                        </li>
                    @endif
                    @foreach (\App\Support\Pagination::pageWindow($list->page, $list->pageCount) as $entry)
                        @if ($entry === null)
                            <li aria-hidden="true" class="{{ $item }} text-muted-foreground">…</li>
                        @else
                            <li>
                                <a
                                    href="{{ $list->pageHref($entry) }}"
                                    @if ($entry === $list->page) aria-current="page" @endif
                                    aria-label="Page {{ $entry }}"
                                    class="{{ $entry === $list->page ? "{$item} bg-primary text-primary-foreground" : $link }}"
                                >{{ $entry }}</a>
                            </li>
                        @endif
                    @endforeach
                    @if ($list->page < $list->pageCount)
                        <li>
                            <a href="{{ $list->pageHref($list->page + 1) }}" rel="next" class="{{ $link }}">Next&nbsp;<span aria-hidden="true">→</span></a>
                        </li>
                    @endif
                </ul>
            </nav>
        @endif
    </div>
@endif
