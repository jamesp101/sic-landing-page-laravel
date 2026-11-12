@props(['departments'])
@php
    $site = \App\Support\Site::SITE;
    $address = $site['address'];
    $footer = \App\Site\SiteNav::footerItems($departments);
    $year = date('Y');
    $socials = array_map(function (string $url): array {
        $host = preg_replace('/^www\./', '', parse_url($url, PHP_URL_HOST));

        return ['url' => $url, 'label' => str_starts_with($host, 'facebook') ? 'Facebook' : (str_starts_with($host, 'tiktok') ? 'TikTok' : $host)];
    }, $site['sameAs']);
    $linkClass = 'inline-flex min-h-11 items-center rounded text-white/75 transition-colors duration-200 hover:text-gold-300 md:min-h-8';
    $heading = 'mb-4 text-xs font-semibold uppercase tracking-[0.2em] text-gold-400';
@endphp
<footer class="on-navy-deep mt-auto border-t border-white/10 bg-brand-950 text-white">
    <div class="page-frame py-14">
        <div class="grid grid-cols-1 gap-10 md:grid-cols-4">
            <div>
                <a href="/" class="flex items-center gap-3 rounded-lg">
                    <img src="/sic-seal-96.webp" alt="" width="44" height="44" loading="lazy" decoding="async" class="h-11 w-11 rounded-full border-2 border-gold-500/40 bg-white/5 object-contain" />
                    <span class="leading-tight">
                        <span class="block font-serif text-lg font-bold">{{ $site['name'] }}</span>
                        <span class="block text-xs uppercase tracking-[0.2em] text-gold-300">{{ $site['motto'] }}</span>
                    </span>
                </a>
                <p class="mt-4 text-sm leading-relaxed text-white/75">{{ $site['tagline'] }}</p>
            </div>

            <nav aria-labelledby="footer-departments">
                <h2 id="footer-departments" class="{{ $heading }} font-sans">
                    Departments
                </h2>
                <ul class="text-sm">
                    @foreach ($footer['departments'] as $link)
                        <li>
                            <a href="{{ $link['href'] }}" class="{{ $linkClass }}">
                                {{ $link['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <nav aria-labelledby="footer-links">
                <h2 id="footer-links" class="{{ $heading }} font-sans">
                    Quick links
                </h2>
                <ul class="text-sm">
                    @foreach ($footer['quickLinks'] as $link)
                        <li>
                            <a href="{{ $link['href'] }}" class="{{ $linkClass }}">
                                {{ $link['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div>
                <h2 class="{{ $heading }} font-sans">Contact us</h2>
                <address class="text-sm not-italic text-white/75">
                    <p class="mb-1">
                        {{ $address['streetAddress'] }}, {{ $address['addressLocality'] }}, {{ $address['addressRegion'] }} {{ $address['postalCode'] }}
                    </p>
                    <p>
                        <a href="mailto:{{ $site['email'] }}" class="{{ $linkClass }}">
                            {{ $site['email'] }}
                        </a>
                    </p>
                    <p>
                        <a href="tel:{{ $site['telephoneE164'] }}" class="{{ $linkClass }}">
                            {{ $site['telephone'] }}
                        </a>
                    </p>
                </address>
                <ul class="mt-2 flex flex-wrap gap-x-5 text-sm" aria-label="Social media">
                    @foreach ($socials as $s)
                        <li>
                            <a href="{{ $s['url'] }}" rel="noopener" class="{{ $linkClass }}">
                                {{ $s['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <p class="mt-12 border-t border-white/10 pt-6 text-xs text-white/70">
            &copy; {{ $year }} {{ $site['name'] }}. All rights reserved.
        </p>
    </div>
</footer>
