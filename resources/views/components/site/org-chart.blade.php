{{-- `nodes`: list of member nodes (id, name, role, imageUrl, imageSmallUrl?, children). `root`: internal, true only for the outermost list. --}}
@props(['nodes', 'root' => true])
@php
    $thumb = \App\Storage\Variants::THUMB_WIDTH;
    $portraitMax = 480; // IMAGE_TARGETS.portrait.maxWidth
@endphp
@if ($root)
    <style nonce="{{ Vite::cspNonce() }}">
        .org-list { list-style: none; margin: 0; padding: 0; }
        .org-card { display: flex; align-items: center; gap: 0.75rem; min-width: 0; padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: 1rem; background: var(--surface); box-shadow: var(--shadow-sm); overflow-wrap: break-word; }
        /* Mobile: indented outline with a left rule. */
        .org-list .org-list { margin-left: 1.25rem; padding-left: 1rem; border-left: 2px solid var(--border-strong); }
        /* From the third level the outline stops moving right, so deep charts stay readable on a phone. */
        @media (max-width: 767.98px) { .org-list .org-list .org-list .org-list { margin-left: 0; padding-left: 0.75rem; } }
        .org-item { margin-top: 0.75rem; }
        .org-root > .org-item + .org-item { margin-top: 2rem; }
        /* Desktop: a tree that never scrolls sideways. A stem runs from each card down to its reports, which sit in
           an outlined group and wrap onto more rows when they do not fit; a group too wide for its row shrinks and
           wraps inside, so the chart grows downwards instead of sideways. A single report hangs from the stem. */
        @media (min-width: 768px) {
            .org-list { display: flex; flex-wrap: wrap; justify-content: center; align-items: flex-start; gap: 1.25rem 1rem; }
            .org-root { gap: 2.5rem; }
            .org-item { display: flex; flex-direction: column; align-items: center; flex: 0 1 auto; min-width: 0; max-width: 100%; margin: 0; }
            .org-root > .org-item + .org-item { margin: 0; }
            .org-card { width: 15rem; max-width: 100%; }
            .org-list .org-list { position: relative; max-width: 100%; margin: 1.5rem 0 0; padding: 1rem; border: 2px solid var(--border-strong); border-radius: 1rem; }
            .org-list .org-list::before { content: ''; position: absolute; bottom: 100%; left: 50%; height: 1.5rem; margin-left: -1px; border-left: 2px solid var(--border-strong); }
            .org-list .org-list:has(> .org-item:only-child) { padding: 0; border: 0; }
        }
    </style>
@endif
<ul @class(['org-list', 'org-root' => $root])>
    @foreach ($nodes as $node)
        <li class="org-item">
            <div class="org-card">
                @if ($node['imageUrl'])
                    <img
                        src="{{ $node['imageUrl'] }}"
                        @if (! empty($node['imageSmallUrl']))
                            srcset="{{ $node['imageSmallUrl'] }} {{ $thumb }}w, {{ $node['imageUrl'] }} {{ $portraitMax }}w"
                            sizes="(min-width: 768px) 64px, 48px"
                        @endif
                        alt=""
                        width="64"
                        height="64"
                        loading="lazy"
                        decoding="async"
                        class="h-12 w-12 shrink-0 rounded-full object-cover md:h-16 md:w-16"
                    />
                @else
                    <span class="flex h-12 w-12 shrink-0 overflow-hidden rounded-full bg-brand-100 md:h-16 md:w-16 text-brand-800 dark:bg-surface-alt dark:text-gold-400" aria-hidden="true">
                        <x-person-silhouette />
                    </span>
                @endif
                <span class="min-w-0">
                    <span class="block text-sm font-semibold leading-snug text-foreground">{{ $node['name'] }}</span>
                    <span class="mt-0.5 block text-xs font-medium uppercase tracking-wider text-link">{{ $node['role'] }}</span>
                </span>
            </div>
            @if (count($node['children']) > 0)
                <x-site.org-chart :nodes="$node['children']" :root="false" />
            @endif
        </li>
    @endforeach
</ul>
