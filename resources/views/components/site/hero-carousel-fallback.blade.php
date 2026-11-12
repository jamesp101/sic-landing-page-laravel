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
                            $class = Layout::layerClasses($layer, $look, false);
                        @endphp
                        @if ($type === 'text')
                            @if ($layer['role'] === 'heading')
                                <h2 class="{{ $class }}" style="{{ $look['style'] }}">{{ $layer['text'] }}</h2>
                            @else
                                <p class="{{ $class }}" style="{{ $look['style'] }}">{{ $layer['text'] }}</p>
                            @endif
                        @elseif ($type === 'button')
                            <a href="{{ $layer['href'] }}" @if ($rel = Overlay::relFor($layer['href'])) rel="{{ $rel }}" @endif class="{{ $class }}" style="{{ $look['style'] }}">
                                {{ $layer['label'] }}<span aria-hidden="true">&rarr;</span>
                            </a>
                        @elseif ($type === 'badge')
                            <span class="{{ $class }}" style="{{ $look['style'] }}">{{ $layer['text'] }}</span>
                        @elseif ($type === 'shape')
                            <div class="{{ $class }}" style="{{ $look['style'] }}" aria-hidden="true"></div>
                        @else
                            <img src="{{ $layer['src'] }}" alt="{{ $layer['alt'] }}" loading="lazy" decoding="async" class="{{ $class }} object-contain" style="{{ $look['style'] }}" />
                        @endif
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>
    </section>
@endif
