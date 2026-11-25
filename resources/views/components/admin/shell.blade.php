{{--
    Server-rendered `admin/AdminShell` (sidebar, header, breadcrumbs) around the page content, so a page paints with its
    sidebar before the island mounts. The island replaces this markup and adopts the content inside
    `data-island-children`. Same markup and classes as the mounted Svelte shell (sidebar-wrapper, inset variant,
    collapsible icon): `e2e/admin-shell.spec.ts` fails when the two lay out differently.
    Props: `shell` (the island props: user, navItems, breadcrumbs, sidebarOpen, scope, scopeDepartments, scopeLocked).
--}}
@props(['shell'])
@php
    use App\Support\Initials;

    $open = $shell['sidebarOpen'];
    $scope = $shell['scope'];
    $scopeLogo = collect($shell['scopeDepartments'])->firstWhere('id', $scope['id'])['logoUrl'] ?? null;
    $button = 'peer/menu-button ring-sidebar-ring flex w-full items-center gap-2 overflow-hidden rounded-md p-2 text-left outline-hidden transition-[width,height,padding,background-color,color] duration-150 ease-out hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 active:bg-sidebar-accent active:text-sidebar-accent-foreground disabled:pointer-events-none disabled:opacity-50 group-has-data-[sidebar=menu-action]/menu-item:pr-8 aria-disabled:pointer-events-none aria-disabled:opacity-50 data-[active=true]:bg-sidebar-accent data-[active=true]:font-medium data-[active=true]:shadow-[inset_3px_0_0_var(--gold-500)] data-[active=true]:text-sidebar-accent-foreground data-[state=open]:hover:bg-sidebar-accent data-[state=open]:hover:text-sidebar-accent-foreground group-data-[collapsible=icon]:size-8!';
    $buttonLarge = $button.' [&>span:last-child]:truncate [&>svg]:size-4 [&>svg]:shrink-0 h-12 text-sm group-data-[collapsible=icon]:p-0!';
    $buttonLink = $button.' group-data-[collapsible=icon]:p-2! [&>span:last-child]:truncate [&>svg]:size-4 [&>svg]:shrink-0 h-8 text-sm';
@endphp
<a href="#main" class="sr-only z-100 rounded-lg bg-gold-500 px-4 py-2 text-sm font-semibold text-brand-950 focus:not-sr-only focus:fixed focus:top-4 focus:left-4">Skip to main content</a>
<div data-slot="sidebar-wrapper" class="group/sidebar-wrapper has-data-[variant=inset]:bg-sidebar flex min-h-svh w-full" style="--sidebar-width: 16rem; --sidebar-width-icon: 3rem">
    <div class="group peer text-sidebar-foreground hidden md:block" data-slot="sidebar" data-state="{{ $open ? 'expanded' : 'collapsed' }}" data-collapsible="{{ $open ? '' : 'icon' }}" data-variant="inset" data-side="left">
        <div class="relative w-(--sidebar-width) bg-transparent transition-[width] duration-200 ease-linear group-data-[collapsible=offcanvas]:w-0 group-data-[side=right]:rotate-180 group-data-[collapsible=icon]:w-[calc(var(--sidebar-width-icon)+(--spacing(4)))]"></div>
        <div class="fixed inset-y-0 z-10 hidden h-svh w-(--sidebar-width) transition-[left,right,width] duration-200 ease-linear md:flex left-0 group-data-[collapsible=offcanvas]:left-[calc(var(--sidebar-width)*-1)] p-2 group-data-[collapsible=icon]:w-[calc(var(--sidebar-width-icon)+(--spacing(4))+2px)]">
            <div data-sidebar="sidebar" class="bg-sidebar group-data-[variant=floating]:border-sidebar-border flex h-full w-full flex-col group-data-[variant=floating]:rounded-lg group-data-[variant=floating]:border group-data-[variant=floating]:shadow-sm">
                <div data-slot="sidebar-header" data-sidebar="header" class="flex flex-col gap-2 p-2">
                    <ul data-slot="sidebar-menu" data-sidebar="menu" class="flex w-full min-w-0 flex-col gap-1">
                        <li data-slot="sidebar-menu-item" data-sidebar="menu-item" class="group/menu-item relative">
                            <a class="{{ $buttonLarge }}" data-slot="sidebar-menu-button" data-sidebar="menu-button" data-size="lg" data-active="false" href="/dashboard">
                                <div class="flex aspect-square size-8 items-center justify-center rounded-md">
                                    <img src="/sic-seal-96.webp" alt="" width="96" height="96" class="size-8 object-contain" />
                                </div>
                                <div class="ml-1 grid flex-1 text-left text-sm"><span class="mb-0.5 truncate font-serif leading-tight font-semibold">San Isidro College</span></div>
                            </a>
                        </li>
                    </ul>
                    <ul data-slot="sidebar-menu" data-sidebar="menu" class="flex w-full min-w-0 flex-col gap-1">
                        <li data-slot="sidebar-menu-item" data-sidebar="menu-item" class="group/menu-item relative">
                            @php
                                $badge = 'flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-secondary text-xs font-semibold text-secondary-foreground';
                            @endphp
                            @if ($shell['scopeLocked'])
                                <div class="flex h-12 w-full items-center gap-2 overflow-hidden rounded-md p-2 group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:p-0" title="{{ $scope['label'] }}" role="group" aria-label="Department: {{ $scope['label'] }}">
                                    <span class="{{ $badge }}" aria-hidden="true">
                                        @if ($scopeLogo)
                                            <img src="{{ $scopeLogo }}" alt="" class="size-full object-cover" />
                                        @else
                                            {{ Initials::getInitials($scope['label']) }}
                                        @endif
                                    </span>
                                    <span class="group-data-[collapsible=icon]:hidden grid min-w-0 flex-1 text-left text-sm leading-tight">
                                        <span class="truncate font-medium">{{ $scope['label'] }}</span>
                                        <span class="truncate text-xs text-muted-foreground">Your department</span>
                                    </span>
                                </div>
                            @else
                                <div class="relative w-full">
                                    <button class="{{ $buttonLarge }} data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground" data-slot="sidebar-menu-button" data-sidebar="menu-button" data-size="lg" data-active="false" type="button" aria-haspopup="menu" aria-label="Department scope: {{ $scope['label'] }}. Change" aria-expanded="false">
                                        <span class="{{ $badge }}" aria-hidden="true">
                                            @if ($scopeLogo)
                                                <img src="{{ $scopeLogo }}" alt="" class="size-full object-cover" />
                                            @elseif ($scope['kind'] === 'all')
                                                <x-icon name="layers" class="size-4" />
                                            @elseif ($scope['kind'] === 'main')
                                                <x-icon name="house" class="size-4" />
                                            @else
                                                {{ Initials::getInitials($scope['label']) }}
                                            @endif
                                        </span>
                                        <span class="group-data-[collapsible=icon]:hidden grid min-w-0 flex-1 text-left text-sm leading-tight">
                                            <span class="truncate font-medium">{{ $scope['label'] }}</span>
                                            <span class="truncate text-xs text-muted-foreground">Department scope</span>
                                        </span>
                                        <x-icon name="chevrons-up-down" class="ml-auto size-4 group-data-[collapsible=icon]:hidden" />
                                    </button>
                                </div>
                            @endif
                        </li>
                    </ul>
                </div>
                <div data-slot="sidebar-content" data-sidebar="content" class="flex min-h-0 flex-1 flex-col gap-2 overflow-auto group-data-[collapsible=icon]:overflow-hidden">
                    <div data-slot="sidebar-group" data-sidebar="group" class="relative flex w-full min-w-0 flex-col p-2 px-2 py-0">
                        <div data-slot="sidebar-group-label" data-sidebar="group-label" class="text-sidebar-foreground/70 ring-sidebar-ring flex h-8 shrink-0 items-center rounded-md px-2 text-xs font-medium outline-hidden transition-[margin,opacity] duration-200 ease-linear focus-visible:ring-2 [&>svg]:size-4 [&>svg]:shrink-0 group-data-[collapsible=icon]:-mt-8 group-data-[collapsible=icon]:opacity-0">Platform</div>
                        <ul data-slot="sidebar-menu" data-sidebar="menu" class="flex w-full min-w-0 flex-col gap-1">
                            @foreach ($shell['navItems'] as $item)
                                <li data-slot="sidebar-menu-item" data-sidebar="menu-item" class="group/menu-item relative">
                                    <a class="{{ $buttonLink }}" data-slot="sidebar-menu-button" data-sidebar="menu-button" data-size="default" data-active="{{ $item['active'] ? 'true' : 'false' }}" href="{{ $item['href'] }}" @if ($item['active']) aria-current="page" @endif>
                                        <x-icon :name="$item['icon']" class="size-4 shrink-0" />
                                        <span>{{ $item['title'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                <div data-slot="sidebar-footer" data-sidebar="footer" class="flex flex-col gap-2 p-2">
                    <ul data-slot="sidebar-menu" data-sidebar="menu" class="flex w-full min-w-0 flex-col gap-1">
                        <li data-slot="sidebar-menu-item" data-sidebar="menu-item" class="group/menu-item relative">
                            <div class="relative w-full">
                                <button type="button" class="{{ $buttonLarge }} data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground" data-slot="sidebar-menu-button" data-sidebar="menu-button" data-size="lg" data-active="false" aria-haspopup="menu" aria-expanded="false">
                                    <div class="relative flex shrink-0 h-8 w-8 overflow-hidden rounded-lg">
                                        <div class="flex h-full w-full items-center justify-center text-sm rounded-lg bg-secondary font-semibold text-secondary-foreground"><x-person-silhouette /></div>
                                    </div>
                                    <div class="grid flex-1 text-left text-sm leading-tight">
                                        <span class="truncate font-medium">{{ $shell['user']['name'] }}</span>
                                    </div>
                                    <x-icon name="chevrons-up-down" class="ml-auto size-4" />
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <div data-slot="sidebar-inset" class="bg-background relative flex w-full flex-1 flex-col md:peer-data-[variant=inset]:m-2 md:peer-data-[variant=inset]:ml-0 md:peer-data-[variant=inset]:rounded-xl md:peer-data-[variant=inset]:shadow-sm md:peer-data-[variant=inset]:peer-data-[state=collapsed]:ml-2 min-w-0 overflow-x-clip">
        <header class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div class="flex items-center gap-2">
                <button class="inline-flex items-center justify-center gap-2 rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 hover:bg-accent hover:text-accent-foreground h-7 w-7 -ml-1" type="button" data-sidebar="trigger" data-slot="sidebar-trigger">
                    {{-- The island shows "open" on phones and while collapsed, "close" on an expanded desktop sidebar. --}}
                    <x-icon name="panel-left-open" @class(['size-4', 'md:hidden' => $open]) />
                    @if ($open)
                        <x-icon name="panel-left-close" class="hidden size-4 md:block" />
                    @endif
                    <span class="sr-only">Toggle sidebar</span>
                </button>
                @if (count($shell['breadcrumbs']) > 0)
                    <nav aria-label="breadcrumb" class="text-sm">
                        <ol class="flex flex-wrap items-center gap-1.5">
                            @foreach ($shell['breadcrumbs'] as $crumb)
                                <li class="inline-flex items-center gap-1.5">
                                    @if ($loop->last || empty($crumb['href']))
                                        <span class="font-medium text-foreground">{{ $crumb['title'] }}</span>
                                    @else
                                        <a class="transition-colors hover:text-foreground" href="{{ $crumb['href'] }}">{{ $crumb['title'] }}</a>
                                    @endif
                                </li>
                                @unless ($loop->last)
                                    <li role="presentation" aria-hidden="true" class="text-muted-foreground">/</li>
                                @endunless
                            @endforeach
                        </ol>
                    </nav>
                @endif
            </div>
        </header>
        <main id="main" class="flex flex-1 flex-col"><div data-island-children style="display: contents">{{ $slot }}</div></main>
    </div>
</div>
