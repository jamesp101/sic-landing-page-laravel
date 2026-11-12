{{--
    Per-page head metadata: title, description, canonical, robots, Open Graph, Twitter card and JSON-LD. Canonical is
    absolute and query-free unless `canonical` is given. `seo` is the page SEO array of App\Site\Seo (title,
    description, canonical, image, imageAlt, type, noindex, follow, publishedTime, modifiedTime, jsonLd).
--}}
@props(['seo' => []])
@php
    use App\Site\Seo;

    $site = \App\Support\Site::SITE;
    $context = Seo::context(request());
    $title = $seo['title'] ?? null;
    $type = $seo['type'] ?? 'website';
    $noindex = $seo['noindex'] ?? false;
    $follow = $seo['follow'] ?? false;
    $fullTitle = $title ? "{$title} | {$site['name']}" : "{$site['name']} — Malaybalay City, Bukidnon";
    $canonicalUrl = Seo::canonicalHref($seo['canonical'] ?? null, $context);
    $ogImage = Seo::absoluteUrl($seo['image'] ?? $site['defaultOgImage'], Seo::siteUrl($context));
    $description = Seo::metaDescription($seo['description'] ?? null) ?: Seo::metaDescription($site['description']);
    $jsonLd = $seo['jsonLd'] ?? [];
    $ld = $jsonLd === [] ? [] : (array_is_list($jsonLd) ? $jsonLd : [$jsonLd]);
@endphp
<title>{{ $fullTitle }}</title>
<meta name="description" content="{{ $description }}" />
<link rel="canonical" href="{{ $canonicalUrl }}" />
<meta name="robots" content="{{ $noindex ? ($follow ? 'noindex, follow' : 'noindex, nofollow') : 'index, follow, max-image-preview:large' }}" />

<meta property="og:site_name" content="{{ $site['name'] }}" />
<meta property="og:locale" content="{{ $site['locale'] }}" />
<meta property="og:type" content="{{ $type }}" />
<meta property="og:title" content="{{ $title ?? $site['name'] }}" />
<meta property="og:description" content="{{ $description }}" />
<meta property="og:url" content="{{ $canonicalUrl }}" />
<meta property="og:image" content="{{ $ogImage }}" />
@if (! empty($seo['imageAlt']))
    <meta property="og:image:alt" content="{{ $seo['imageAlt'] }}" />
@endif
@if (! empty($seo['publishedTime']))
    <meta property="article:published_time" content="{{ Seo::iso($seo['publishedTime']) }}" />
@endif
@if (! empty($seo['modifiedTime']))
    <meta property="article:modified_time" content="{{ Seo::iso($seo['modifiedTime']) }}" />
@endif

<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="{{ $title ?? $site['name'] }}" />
<meta name="twitter:description" content="{{ $description }}" />
<meta name="twitter:image" content="{{ $ogImage }}" />

@foreach ($ld as $item)
    <script type="application/ld+json" nonce="{{ Vite::cspNonce() }}">{!! Seo::jsonLdScript($item) !!}</script>
@endforeach
