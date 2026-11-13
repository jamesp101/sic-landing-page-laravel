{{-- Server-rendered `site/HeroCarousel` (what Astro's SSR of the island produced): every Slide with its Layers (the
     first one showing, the others inert), the first photo with `srcset`/`sizes`, and the page `h1`, for visitors
     without JS, crawlers and the first paint (LCP). Meant for the island's slot; the mounted carousel replaces it.
     No controls, no video.
     Props: `slides` (App\Carousel\Slides::findSlides: Layers arrive presented, `stage` and `focus`), `tall` (home vs Department height), `label`, `pageTitle`. --}}
@props(['slides', 'tall' => true, 'label' => 'Featured highlights', 'pageTitle' => null])
@php
    use App\Carousel\Layout;
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
                $first = $index === 0;
            @endphp
            <div
                role="group"
                aria-roledescription="slide"
                aria-label="{{ $index + 1 }} of {{ count($slides) }}"
                tabindex="-1"
                @unless ($first) inert @endunless
                class="slide absolute inset-0 transition-opacity duration-1000 ease-in-out focus-visible:ring-inset focus-visible:ring-offset-0 {{ $first ? 'z-10 opacity-100 is-active' : 'z-0 opacity-0' }}"
                style="--fx: {{ $slide['focus']['x'] }}%; --fy: {{ $slide['focus']['y'] }}%"
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
                    @foreach ($slide['stage'] as $element)
                        @if ($element['tag'] === 'img')
                            <img @foreach ($element['attrs'] as $name => $value) {{ $name }}="{{ $value }}" @endforeach />
                        @else
                            <{{ $element['tag'] }} @foreach ($element['attrs'] as $name => $value) {{ $name }}="{{ $value }}" @endforeach>{{ $element['text'] }}@if ($element['arrow'])<span aria-hidden="true">&rarr;</span>@endif</{{ $element['tag'] }}>
                        @endif
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>
    </section>
@endif
