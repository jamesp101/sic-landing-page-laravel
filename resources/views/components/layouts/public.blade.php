{{--
    Public page shell: skip link, header, <main id="main">, footer, and layout-level JSON-LD (WebSite on the home
    page, BreadcrumbList when crumbs are given).
    Props: `seo` (page SEO array of App\Site\Seo, `breadcrumbs` included), `layout` (App\Site\SiteLayout::read(), started
    here when omitted), `bodyClass`, `minimal` (skip database lookups; used by the 500 page).
--}}
@props(['seo' => [], 'layout' => null, 'bodyClass' => '', 'minimal' => false])
@php
    use App\Site\Seo;
    use App\Site\SiteLayout;

    $breadcrumbs = $seo['breadcrumbs'] ?? [];
    $layout = $minimal ? ['navLinks' => [], 'departments' => []] : ($layout ?? SiteLayout::read());
    $context = Seo::context(request());
    $site = Seo::siteUrl($context);
    $canonicalUrl = Seo::canonicalHref($seo['canonical'] ?? null, $context);
    $extra = $seo['jsonLd'] ?? [];
    $extra = $extra === [] ? [] : (array_is_list($extra) ? $extra : [$extra]);
    $ld = [
        ...$extra,
        ...(count($breadcrumbs) > 0 ? [Seo::breadcrumbList($breadcrumbs, $site, $canonicalUrl)] : []),
        ...(request()->path() === '/' ? [Seo::webSite($site)] : []),
    ];
    $baseSeo = [...$seo, 'jsonLd' => $ld];
    unset($baseSeo['breadcrumbs']);
@endphp
<x-layouts.base :seo="$baseSeo" entry="site" :bodyClass="'flex min-h-screen flex-col '.$bodyClass">
    <x-slot:head>
        <link rel="manifest" href="/manifest.webmanifest" />
        <noscript>
            <style nonce="{{ Vite::cspNonce() }}">
                /* No JS: keep navigation usable. */
                .nav-toggle {
                    display: none;
                }
                .nav-list {
                    display: flex !important;
                    position: static !important;
                    max-height: none !important;
                    overflow: visible !important;
                    flex-direction: row;
                    flex-wrap: wrap;
                    padding: 0 !important;
                    border: 0 !important;
                    background: transparent !important;
                    box-shadow: none !important;
                }
                .nav-group:focus-within .nav-sub {
                    display: flex !important;
                }
            </style>
        </noscript>
    </x-slot:head>
    <a
        href="#main"
        class="sr-only z-100 rounded-lg bg-gold-500 px-4 py-2 text-sm font-semibold text-brand-950 focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
    >
        Skip to main content
    </a>
    <x-site.site-header :navLinks="$layout['navLinks']" :departments="$layout['departments']" />
    @if (count($breadcrumbs) > 0)
        <x-site.site-breadcrumbs :crumbs="$breadcrumbs" />
    @endif
    <main id="main" class="flex-1">
        {{ $slot }}
    </main>
    <x-site.site-footer :departments="$layout['departments']" />
</x-layouts.base>
