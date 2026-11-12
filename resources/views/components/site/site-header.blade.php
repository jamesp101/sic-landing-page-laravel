@props(['navLinks', 'departments'])
@php
    $site = \App\Support\Site::SITE;
    $items = \App\Site\SiteNav::headerItems($navLinks, $departments);
    $currentPath = '/'.ltrim(request()->path(), '/');
    // Server-rendered menu (what shows without JavaScript); `site/NavMenu` replaces it on mount. Same markup and classes as NavMenu.svelte, closed.
    $marker = 'relative after:pointer-events-none after:absolute after:inset-y-2 after:left-0 after:w-1 after:rounded-full after:bg-gold-400 after:opacity-0 after:transition-opacity after:duration-200 md:after:inset-y-auto md:after:bottom-1 md:after:left-3 md:after:right-3 md:after:h-0.5 md:after:w-auto';
    $itemBase = 'min-h-11 items-center rounded-lg px-3 py-2 text-sm font-medium text-white/90 transition-colors duration-200 ease-out hover:text-gold-300 md:min-h-10';
    $link = "{$marker} {$itemBase} flex aria-[current=page]:bg-white/10 aria-[current=page]:text-gold-300 aria-[current=page]:after:opacity-100 md:aria-[current=page]:bg-transparent";
@endphp
<header data-site-header class="on-navy sticky top-0 z-50 border-b border-white/10 bg-linear-to-r from-brand-800 to-brand-700 text-white shadow-brand-md">
    <div class="page-frame flex items-center justify-between gap-4 py-3">
        <a href="/" class="flex items-center gap-3 rounded-lg" aria-label="{{ $site['name'] }}, {{ $site['motto'] }} — home">
            <img src="/sic-seal-96.webp" alt="" width="44" height="44" decoding="async" class="h-11 w-11 rounded-full border-2 border-gold-500/40 bg-white/5 object-contain" />
            <span class="leading-tight">
                <span class="block font-serif text-lg font-bold tracking-tight">{{ $site['name'] }}</span>
                <span class="block text-xs uppercase tracking-[0.2em] text-gold-300">{{ $site['motto'] }}</span>
            </span>
        </a>

        <div class="flex items-center gap-2">
            <x-island name="site/NavMenu" :props="['items' => $items, 'currentPath' => $currentPath]" class="contents">
                <nav aria-label="Primary" class="flex items-center">
                    <ul id="primary-menu" class="nav-list absolute inset-x-0 top-full hidden max-h-[calc(100dvh-4.5rem)] flex-col gap-1 overflow-y-auto border-t border-white/10 bg-brand-800 px-6 py-4 shadow-brand-lg md:static md:flex md:max-h-none md:flex-row md:items-center md:gap-1 md:overflow-visible md:border-0 md:bg-transparent md:p-0 md:shadow-none">
                        @foreach ($items as $item)
                            <li class="nav-group relative">
                                @if (! empty($item['children']))
                                    @php($submenu = 'submenu-'.strtolower(trim(preg_replace('/\W+/', '-', $item['label']), '')))
                                    <button type="button" data-group="{{ $item['label'] }}" class="{{ $marker }} {{ $itemBase }} flex w-full justify-between gap-1 data-[active]:text-gold-300 data-[active]:after:opacity-100 md:w-auto" aria-expanded="false" aria-controls="{{ $submenu }}">{{ $item['label'] }}</button>
                                    <ul id="{{ $submenu }}" class="nav-sub mt-1 hidden flex-col gap-0.5 border-l border-white/15 pl-3 md:absolute md:left-0 md:top-full md:z-50 md:mt-0 md:w-72 md:rounded-xl md:border md:border-white/10 md:bg-brand-800 md:p-2 md:shadow-brand-lg">
                                        @foreach ($item['children'] as $child)
                                            <li><a href="{{ $child['href'] }}" class="{{ $link }} md:hover:bg-white/10" @if ($child['href'] === $currentPath) aria-current="page" @endif>{{ $child['label'] }}</a></li>
                                        @endforeach
                                    </ul>
                                @else
                                    <a href="{{ $item['href'] }}" class="{{ $link }}" @if ($item['href'] === $currentPath) aria-current="page" @endif>{{ $item['label'] }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </nav>
            </x-island>
            <x-island name="site/ThemeToggle" class="contents" />
        </div>
    </div>
</header>
