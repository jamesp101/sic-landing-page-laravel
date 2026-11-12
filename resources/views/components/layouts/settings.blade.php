{{-- Settings shell: admin app layout + sub-navigation (Profile, Security, Appearance). Props: title. --}}
@props(['title'])
@php
    $items = [
        ['title' => 'Profile', 'href' => '/settings/profile'],
        ['title' => 'Security', 'href' => '/settings/security'],
        ['title' => 'Appearance', 'href' => '/settings/appearance'],
    ];
    $path = '/'.trim(request()->path(), '/');
@endphp
<x-layouts.app :title="$title.' settings'" :breadcrumbs="[['title' => 'Settings', 'href' => '/settings/profile'], ['title' => $title]]">
    <div class="mx-auto w-full max-w-7xl p-6">
        <header class="mb-8 space-y-1">
            <p class="flex items-center gap-2 text-xs font-semibold tracking-widest text-link uppercase">
                <span class="h-0.5 w-6 bg-gold-500" aria-hidden="true"></span>
                Settings
            </p>
            <p class="text-sm text-muted-foreground">Manage your profile and account settings</p>
        </header>

        <div class="flex flex-col lg:flex-row lg:gap-12">
            <nav class="w-full max-w-xl lg:w-48" aria-label="Settings">
                <ul class="flex flex-col gap-1">
                    @foreach ($items as $item)
                        <li>
                            <a
                                href="{{ $item['href'] }}"
                                @if ($path === $item['href']) aria-current="page" @endif
                                @class([
                                    'block rounded-md px-3 py-2 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground',
                                    'bg-muted text-foreground' => $path === $item['href'],
                                    'text-muted-foreground' => $path !== $item['href'],
                                ])
                            >
                                {{ $item['title'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
            <hr class="my-6 border-border lg:hidden" />
            <section class="max-w-xl flex-1 space-y-12 md:max-w-2xl">
                {{ $slot }}
            </section>
        </div>
    </div>
</x-layouts.app>
