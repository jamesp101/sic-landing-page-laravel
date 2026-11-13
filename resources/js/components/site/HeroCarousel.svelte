<script lang="ts">
    import { ChevronLeft, ChevronRight, Pause, Play } from '@lucide/svelte';
    import { onMount, tick } from 'svelte';
    import { SvelteSet } from 'svelte/reactivity';
    import { heroImageSizes } from '@/lib/carousel-frame';
    import {
        autoplay as isAutoplaying,
        canAutoplay as canAutoplayNow,
        classifySwipe,
        isMultiple,
        keyTarget,
        preloadWindow,
        showPill as shouldShowPill,
        SLIDE_MS,
        startsSwipe,
        videoAllowed as isVideoAllowed,
        videoRunning as isVideoRunning,
        wrapIndex,
        type PlaybackInputs,
    } from '@/lib/carousel-playback';
    import type { CarouselSlide } from '@/lib/content-types';
    import SlideLayer from './SlideLayer.svelte';
    import SlideVideo from './SlideVideo.svelte';

    let {
        slides,
        label = 'Featured highlights',
        pageTitle,
        tall = true,
    }: {
        slides: CarouselSlide[];
        label?: string;
        /** Visually hidden h1 for pages whose visible h1 is not part of the carousel. */
        pageTitle?: string;
        /** Home height (600px) vs department height (450px). */
        tall?: boolean;
    } = $props();

    let root: HTMLElement;
    const slideEls: HTMLElement[] = $state([]);

    let current = $state(0);
    /** The slide fading out, kept zoomed so its photo does not jump while it disappears. */
    let previous = $state(-1);
    let mounted = $state(false);
    let userPaused = $state(false);
    let hovering = $state(false);
    let focused = $state(false);
    let reducedMotion = $state(false);
    /** The visitor asked the browser to save data: slides stay on their photo. */
    let saveData = $state(false);
    let inView = $state(true);
    let pageVisible = $state(true);
    /** Slides whose photo may load besides the first: the current slide and its neighbours. */
    const loaded = new SvelteSet<CarouselSlide['id']>();

    const playback = $derived<PlaybackInputs>({
        mounted,
        slideCount: slides.length,
        hasVideo: slides.some((slide) => slide.video),
        reducedMotion,
        saveData,
        userPaused,
        hovering,
        focused,
        inView,
        pageVisible,
    });
    const multiple = $derived(isMultiple(slides.length));
    const canAutoplay = $derived(canAutoplayNow(playback));
    const videoAllowed = $derived(isVideoAllowed(playback));
    const videoRunning = $derived(isVideoRunning(playback));
    const showPill = $derived(shouldShowPill(playback));
    const autoplay = $derived(isAutoplaying(playback));
    const pad = (n: number) => String(n).padStart(2, '0');

    onMount(() => {
        const media = matchMedia('(prefers-reduced-motion: reduce)');
        reducedMotion = media.matches;
        const onMotion = () => (reducedMotion = media.matches);
        media.addEventListener('change', onMotion);

        const connection = (navigator as Navigator & { connection?: EventTarget & { saveData?: boolean } }).connection;
        const onSaveData = () => (saveData = connection?.saveData === true);
        onSaveData();
        connection?.addEventListener('change', onSaveData);

        const onVisibility = () => (pageVisible = document.visibilityState === 'visible');
        onVisibility();
        document.addEventListener('visibilitychange', onVisibility);

        const observer = new IntersectionObserver(([entry]) => (inView = entry.isIntersecting), { threshold: 0.25 });
        observer.observe(root);

        mounted = true;
        return () => {
            media.removeEventListener('change', onMotion);
            connection?.removeEventListener('change', onSaveData);
            document.removeEventListener('visibilitychange', onVisibility);
            observer.disconnect();
        };
    });

    // Fetch the photos around the current slide ahead of time instead of all of them at page load.
    $effect(() => {
        if (!mounted) return;
        for (const index of preloadWindow(current, slides.length)) loaded.add(slides[index].id);
    });

    function go(index: number) {
        const target = wrapIndex(index, slides.length);
        if (target === current) return;
        // The old slide turns inert, which drops focus; carry keyboard users to the new slide.
        const focusInside = slideEls[current]?.contains(document.activeElement) ?? false;
        previous = current;
        current = target;
        if (focusInside) tick().then(() => slideEls[current]?.focus());
    }
    const next = () => go(current + 1);
    const prev = () => go(current - 1);

    function onkeydown(event: KeyboardEvent) {
        const target = keyTarget(event.key, event, current, slides.length);
        if (target === undefined) return;
        event.preventDefault();
        go(target);
    }

    // Touch swipe. `touch-action: pan-y` leaves vertical scrolling to the browser.
    let swipe: { id: number; x: number; y: number } | null = null;
    function onpointerdown(event: PointerEvent) {
        if (!startsSwipe(slides.length, event.pointerType, event.isPrimary)) return;
        swipe = { id: event.pointerId, x: event.clientX, y: event.clientY };
    }
    function onpointerup(event: PointerEvent) {
        if (!swipe || event.pointerId !== swipe.id) return;
        const direction = classifySwipe(event.clientX - swipe.x, event.clientY - swipe.y);
        swipe = null;
        if (direction === 'next') next();
        else if (direction === 'previous') prev();
    }

    const arrow =
        'absolute top-1/2 z-20 hidden h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full border border-white/20 bg-brand-950/40 text-white backdrop-blur-sm transition hover:border-white/40 hover:bg-brand-950/75 md:inline-flex';
</script>

<!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
<section
    bind:this={root}
    aria-roledescription="carousel"
    aria-label={label}
    class="slide-hero on-navy-deep relative w-full overflow-hidden bg-brand-950 {tall ? '' : 'slide-hero-short'}"
    onpointerenter={(event) => event.pointerType === 'mouse' && (hovering = true)}
    onpointerleave={(event) => event.pointerType === 'mouse' && (hovering = false)}
    onfocusin={(event) => (focused = (event.target as Element).matches(':focus-visible'))}
    onfocusout={() => (focused = false)}
    {onkeydown}
    {onpointerdown}
    {onpointerup}
    onpointercancel={() => (swipe = null)}
>
    {#if pageTitle}<h1 class="sr-only">{pageTitle}</h1>{/if}

    <div aria-live={autoplay ? 'off' : 'polite'} class="h-full">
        {#each slides as slide, index (slide.id)}
            <div
                bind:this={slideEls[index]}
                role="group"
                aria-roledescription="slide"
                aria-label="{index + 1} of {slides.length}"
                tabindex="-1"
                inert={index !== current}
                class="slide absolute inset-0 transition-opacity duration-1000 ease-in-out focus-visible:ring-inset focus-visible:ring-offset-0 {index ===
                current
                    ? 'z-10 opacity-100'
                    : 'z-0 opacity-0'}"
                class:is-active={index === current}
                style:--fx="{slide.focus.x}%"
                style:--fy="{slide.focus.y}%"
            >
                <img
                    src={index === 0 || loaded.has(slide.id) ? slide.imageUrl : undefined}
                    srcset={slide.imageMediumUrl && (index === 0 || loaded.has(slide.id))
                        ? `${slide.imageMediumUrl} 960w, ${slide.imageUrl} 1920w`
                        : undefined}
                    sizes={slide.imageMediumUrl ? heroImageSizes(tall) : undefined}
                    alt=""
                    width="1920"
                    height="1080"
                    loading={index === 0 ? 'eager' : 'lazy'}
                    fetchpriority={index === 0 ? 'high' : undefined}
                    decoding="async"
                    class="slide-photo absolute inset-0 h-full w-full object-cover"
                    class:zoom={index === current || index === previous}
                    style:transform-origin={index % 2 ? '70% 60%' : '30% 40%'}
                />
                {#if slide.video && videoAllowed && (slide.video.kind === 'file' ? loaded.has(slide.id) : index === current)}
                    <!-- Background video: the photo above is the poster until it plays. Other slides' files stay paused; YouTube only loads for the active slide. -->
                    <SlideVideo video={slide.video} play={index === current && videoRunning} />
                {/if}
                <div
                    class="absolute inset-0 bg-linear-to-t from-brand-950/90 via-brand-950/45 to-brand-950/30 md:from-brand-950/85"
                ></div>
                <!-- Real, server-rendered text, links and images positioned over the picture. -->
                <div class="slide-stage rise">
                    {#each slide.stage as element (element.id)}
                        <SlideLayer {element} />
                    {/each}
                </div>
            </div>
        {/each}
    </div>

    {#if multiple && mounted}
        <button type="button" class="{arrow} left-6" aria-label="Previous slide" onclick={prev}>
            <ChevronLeft class="h-6 w-6" aria-hidden="true" />
        </button>
        <button type="button" class="{arrow} right-6" aria-label="Next slide" onclick={next}>
            <ChevronRight class="h-6 w-6" aria-hidden="true" />
        </button>
    {/if}

    {#if showPill}
        <div class="absolute inset-x-0 bottom-4 z-20 flex justify-center px-4 md:bottom-6">
            <div
                class="flex items-center gap-1 rounded-full bg-brand-950/45 py-1 pl-1 text-white backdrop-blur-sm {multiple
                    ? 'pr-4'
                    : 'pr-1'}"
            >
                {#if !reducedMotion}
                    <button
                        type="button"
                        class="inline-flex h-11 w-11 items-center justify-center rounded-full transition-colors duration-200 hover:bg-white/15"
                        aria-label={userPaused
                            ? multiple
                                ? 'Play slideshow'
                                : 'Play video'
                            : multiple
                              ? 'Pause slideshow'
                              : 'Pause video'}
                        onclick={() => (userPaused = !userPaused)}
                    >
                        {#if userPaused}
                            <Play class="h-4 w-4" aria-hidden="true" />
                        {:else}
                            <Pause class="h-4 w-4" aria-hidden="true" />
                        {/if}
                    </button>
                {:else}
                    <span class="w-3" aria-hidden="true"></span>
                {/if}
                {#if multiple}
                    <div class="flex">
                        {#each slides as slide, index (slide.id)}
                            <button
                                type="button"
                                class="group flex h-11 items-center px-1"
                                aria-label="Go to slide {index + 1}"
                                aria-current={index === current ? 'true' : undefined}
                                onclick={() => go(index)}
                            >
                                <span
                                    class="relative block h-1.5 overflow-hidden rounded-full transition-all duration-300 {index ===
                                    current
                                        ? 'w-10 bg-white/30'
                                        : 'w-5 bg-white/50 group-hover:bg-white/80'}"
                                >
                                    {#if index === current}
                                        <!-- Autoplay is driven by this bar: when it fills up, the next slide shows. -->
                                        <span
                                            class="progress absolute inset-0 origin-left rounded-full bg-gold-400"
                                            class:timed={canAutoplay}
                                            class:running={autoplay}
                                            style:animation-duration="{SLIDE_MS}ms"
                                            onanimationend={next}
                                        ></span>
                                    {/if}
                                </span>
                            </button>
                        {/each}
                    </div>
                    <span
                        class="ml-2 text-xs font-semibold tabular-nums tracking-widest text-white/85"
                        aria-hidden="true"
                    >
                        {pad(current + 1)}<span class="px-1 text-white/50">/</span><span class="text-white/50"
                            >{pad(slides.length)}</span
                        >
                    </span>
                {/if}
            </div>
        </div>
    {/if}
</section>

<style>
    /* Slow zoom on the visible photo; the outgoing photo keeps its zoom while it fades out. */
    .slide-photo.zoom {
        animation: ken-burns 12s ease-out both;
    }
    @keyframes ken-burns {
        from {
            transform: scale(1);
        }
        to {
            transform: scale(1.08);
        }
    }

    /*
   * Text and layers rise in, one after another, every time their slide becomes active.
   * `translate` composes with the layers' own `transform`, so designer rotations are kept.
   */
    .is-active .rise > :global(*) {
        animation: rise 0.9s cubic-bezier(0.2, 0.7, 0.2, 1) 0.75s both;
    }
    .is-active .rise > :global(:nth-child(1)) {
        animation-delay: 0.3s;
    }
    .is-active .rise > :global(:nth-child(2)) {
        animation-delay: 0.42s;
    }
    .is-active .rise > :global(:nth-child(3)) {
        animation-delay: 0.54s;
    }
    .is-active .rise > :global(:nth-child(4)) {
        animation-delay: 0.66s;
    }
    @keyframes rise {
        from {
            opacity: 0;
            translate: 0 1.25rem;
        }
    }

    .progress.timed {
        animation-name: progress;
        animation-timing-function: linear;
        animation-fill-mode: both;
        animation-play-state: paused;
    }
    .progress.running {
        animation-play-state: running;
    }
    @keyframes progress {
        from {
            transform: scaleX(0);
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .slide-photo.zoom,
        .is-active .rise > :global(*) {
            animation: none;
        }
    }
</style>
