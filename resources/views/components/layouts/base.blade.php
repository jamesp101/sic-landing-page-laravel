{{--
    Document shell shared by every layout: <html lang>, SEO head, fonts, theme bootstrap (no flash of wrong
    theme). Theme is applied client-side only so publicly cached HTML is identical for every visitor.
    Props: `seo` (array for <x-seo>: title, description, canonical, image, type, noindex, jsonLd, …),
    `entry` (Vite entry: `site` or `admin`), `bodyClass`. Slots: default, `head`.
--}}
@props(['seo' => [], 'entry' => 'site', 'bodyClass' => ''])
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="theme-color" content="#0a2e6e" />
        <meta name="color-scheme" content="light dark" />
        {{-- Only on private (never cached) pages: public HTML is identical for every visitor. --}}
        @if ($entry === 'admin')
            <meta name="csrf-token" content="{{ csrf_token() }}" />
        @endif
        <link rel="icon" href="/favicon.ico" sizes="any" />
        <link rel="icon" href="/icon-192.png" type="image/png" sizes="192x192" />
        <link rel="apple-touch-icon" href="/apple-touch-icon.png" />
        <link rel="alternate" type="application/rss+xml" title="San Isidro College — Posts" href="/rss.xml" />
        <link rel="sitemap" href="/sitemap.xml" />
        @foreach (\App\Support\FontPreload::hrefs() as $font)
            <link rel="preload" href="{{ $font }}" as="font" type="font/woff2" crossorigin />
        @endforeach
        <script nonce="{{ Vite::cspNonce() }}">{!! file_get_contents(resource_path('js/scripts/theme-init.js')) !!}</script>
        <x-seo :seo="$seo" />
        @vite(['resources/css/app.css', "resources/js/{$entry}.ts"])
        {{ $head ?? '' }}
    </head>
    <body class="{{ $bodyClass }}">
        {{ $slot }}
    </body>
</html>
