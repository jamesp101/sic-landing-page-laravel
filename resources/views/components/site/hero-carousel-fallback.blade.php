{{-- Server-rendered `site/HeroCarousel` (what Astro's SSR of the island produced): every Slide with its Layers (the
     first one showing, the others inert), the first photo with `srcset`/`sizes`, and the page `h1`, for visitors
     without JS, crawlers and the first paint (LCP). Meant for the island's slot; the mounted carousel replaces it.
     No controls, no video.
     Props: `slides` (App\Carousel\Slides::findSlides), `tall` (home vs Department height), `label`, `pageTitle`. --}}
@props(['slides', 'tall' => true, 'label' => 'Featured highlights', 'pageTitle' => null])
@php
    use App\Carousel\Layout;
    use App\Carousel\Overlay;
    use App\Carousel\OverlayMobile;

@endphp
@if (count($slides) > 0)
    <section
        aria-roledescription="carousel"
        aria-label="{{ $label }}"
        @class(['slide-hero on-navy-deep relative w-full overflow-hidden bg-brand-950', 'slide-hero-short' => ! $tall])
    >
        @if ($pageTitle)
            <h1 class="sr-only">{{ $pageTitle }}</h1>
        @endif
        <div aria-live="polite" class="h-full">
            @foreach ($slides as $index => $slide)
            @php
                $phone = OverlayMobile::resolveMobile($slide['layers']);
                $focus = OverlayMobile::slideFocus($slide['mobile']);
                $first = $index === 0;
            @endphp
            <div
                role="group"
                aria-roledescription="slide"
                aria-label="{{ $index + 1 }} of {{ count($slides) }}"
                tabindex="-1"
                @unless ($first) inert @endunless
                class="slide absolute inset-0 transition-opacity duration-1000 ease-in-out focus-visible:ring-inset focus-visible:ring-offset-0 {{ $first ? 'z-10 opacity-100 is-active' : 'z-0 opacity-0' }}"
                style="--fx: {{ $focus['x'] }}%; --fy: {{ $focus['y'] }}%"
            >
                <img
                    @if ($first) src="{{ $slide['imageUrl'] }}" @endif
                    @if (! empty($slide['imageMediumUrl']))
                        @if ($first) srcset="{{ $slide['imageMediumUrl'] }} 960w, {{ $slide['imageUrl'] }} 1920w" @endif
                        sizes="{{ Layout::heroImageSizes($tall) }}"
                    @endif
                    alt=""
                    width="1920"
                    height="1080"
                    loading="{{ $first ? 'eager' : 'lazy' }}"
                    @if ($first) fetchpriority="high" @endif
                    decoding="async"
                    class="slide-photo absolute inset-0 h-full w-full object-cover {{ $first ? 'zoom' : '' }}"
                    style="transform-origin: {{ $index % 2 ? '70% 60%' : '30% 40%' }}"
                />
                <div class="absolute inset-0 bg-linear-to-t from-brand-950/90 via-brand-950/45 to-brand-950/30 md:from-brand-950/85"></div>
                <div class="slide-stage rise">
                    @foreach (Overlay::readingOrder($slide['layers']) as ['layer' => $layer, 'z' => $z])
                        @php
                            $look = Layout::layerPresentation($layer, $z, $phone[$layer['id']] ?? null, 'public');
                            $type = $layer['type'];
                            $textClass = $type === 'text' ? implode(' ', [
                                'sl sl-fs sl-text', $look['phoneClass'],
                                Overlay::ALIGN_CLASS[$layer['align']],
                                $look['textColor'],
                                'whitespace-pre-line break-words',
                                // Serif is reserved for headings (h1/h2) by the design rules.
                                $layer['role'] === 'heading' && $layer['font'] === 'serif' ? 'font-serif' : 'font-sans',
                                $layer['role'] === 'heading' ? 'font-bold leading-tight tracking-tight' : 'leading-snug',
                            ]) : '';
                            $buttonClass = $type === 'button' ? implode(' ', [
                                'sl sl-fs', $look['phoneClass'],
                                'inline-flex min-w-[10em] items-center justify-center gap-[0.6em] rounded-xl border-2 px-[1.5em] py-[0.8em] font-semibold uppercase tracking-widest leading-none',
                                $layer['variant'] === 'solid'
                                    ? 'border-transparent '.Overlay::BG_COLOR_CLASS[$layer['color']].' '.Overlay::ON_COLOR_CLASS[$layer['color']].' shadow-brand-md'
                                    : 'border-current '.$look['textColor'],
                                'transition hover:-translate-y-0.5',
                            ]) : '';
                            $badgeClass = $type === 'badge'
                                ? "sl sl-fs {$look['phoneClass']} inline-block rounded-full px-[0.9em] py-[0.35em] font-semibold uppercase leading-none tracking-wider ".Overlay::BG_COLOR_CLASS[$layer['color']].' '.Overlay::ON_COLOR_CLASS[$layer['color']]
                                : '';
                            $shapeClass = $type === 'shape' ? "sl sl-shape {$look['phoneClass']} ".Overlay::BG_COLOR_CLASS[$layer['color']].' '.Overlay::RADIUS_CLASS[$layer['radius']] : '';
                            $imageClass = $type === 'image' ? "sl sl-img {$look['phoneClass']} ".Overlay::RADIUS_CLASS[$layer['radius']] : '';
                        @endphp
                        @if ($type === 'text')
                            @if ($layer['role'] === 'heading')
                                <h2 class="{{ $textClass }}" style="{{ $look['style'] }}">{{ $layer['text'] }}</h2>
                            @else
                                <p class="{{ $textClass }}" style="{{ $look['style'] }}">{{ $layer['text'] }}</p>
                            @endif
                        @elseif ($type === 'button')
                            <a href="{{ $layer['href'] }}" @if ($rel = Overlay::relFor($layer['href'])) rel="{{ $rel }}" @endif class="{{ $buttonClass }}" style="{{ $look['style'] }}">
                                {{ $layer['label'] }}<span aria-hidden="true">&rarr;</span>
                            </a>
                        @elseif ($type === 'badge')
                            <span class="{{ $badgeClass }}" style="{{ $look['style'] }}">{{ $layer['text'] }}</span>
                        @elseif ($type === 'shape')
                            <div class="{{ $shapeClass }}" style="{{ $look['style'] }}" aria-hidden="true"></div>
                        @else
                            <img src="{{ $layer['src'] }}" alt="{{ $layer['alt'] }}" loading="lazy" decoding="async" class="{{ $imageClass }} object-contain" style="{{ $look['style'] }}" />
                        @endif
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>
    </section>
@endif
