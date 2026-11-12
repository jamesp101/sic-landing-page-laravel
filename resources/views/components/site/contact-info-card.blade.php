@php
    $site = \App\Support\Site::SITE;
    $address = $site['address'];
    $link = 'inline-flex min-h-11 items-center rounded font-medium text-foreground underline-offset-4 hover:underline md:min-h-8';
    $box = 'rounded-2xl border border-border bg-surface p-6 shadow-brand-sm';
    $heading = 'font-sans text-sm font-semibold uppercase tracking-wider text-link';
@endphp
<div class="space-y-4">
    <div class="{{ $box }}">
        <h3 class="{{ $heading }}">Campus address</h3>
        <address class="mt-2 text-sm not-italic leading-relaxed text-foreground">
            {{ $site['name'] }}
            <br />
            {{ $address['streetAddress'] }}, {{ $address['addressLocality'] }}
            <br />
            {{ $address['addressRegion'] }} {{ $address['postalCode'] }}, Philippines
        </address>
    </div>
    <div class="{{ $box }}">
        <h3 class="{{ $heading }}">Phone</h3>
        <p class="mt-1 text-sm">
            <a href="tel:{{ $site['telephoneE164'] }}" class="{{ $link }}">
                {{ $site['telephone'] }}
            </a>
        </p>
    </div>
    <div class="{{ $box }}">
        <h3 class="{{ $heading }}">Email</h3>
        <p class="mt-1 text-sm">
            <a href="mailto:{{ $site['email'] }}" class="{{ $link }}">
                {{ $site['email'] }}
            </a>
        </p>
    </div>
    <div class="{{ $box }}">
        <h3 class="{{ $heading }}">Follow us</h3>
        <ul class="mt-1 flex flex-wrap gap-x-5 text-sm">
            @foreach ($site['sameAs'] as $url)
                <li>
                    <a href="{{ $url }}" rel="noopener" class="{{ $link }}">
                        {{ str_contains($url, 'facebook') ? 'Facebook' : (str_contains($url, 'tiktok') ? 'TikTok' : parse_url($url, PHP_URL_HOST)) }}
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</div>
