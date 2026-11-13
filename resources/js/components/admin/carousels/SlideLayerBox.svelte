<script lang="ts">
    import { RADIUS_CLASS, TYPE_LABEL, layerLabel, type Layer } from '@/lib/carousel-overlay';
    import { layerClasses, layerPresentation, type SlideMode } from '@/lib/carousel-layout';
    import type { MobileBox } from '@/lib/carousel-overlay-mobile';

    let {
        layer,
        z,
        mode,
        interactive = false,
        box = null,
    }: {
        layer: Layer;
        /** Stacking order (1 = bottom). */
        z: number;
        /** Which layout the designer previews: inert, focusable boxes (the public Carousel prints `site/SlideLayer`). */
        mode: SlideMode;
        interactive?: boolean;
        /** Phone box of the layer (resolveMobile). */
        box?: MobileBox | null;
    } = $props();

    const look = $derived(layerPresentation(layer, z, box, mode));
    const className = $derived(layerClasses(layer, look));
</script>

<!-- svelte-ignore a11y_no_noninteractive_tabindex -->
<div
    class="{className} cursor-grab select-none {look.ghostClass}"
    style={look.style}
    data-layer-id={layer.id}
    role={interactive ? 'button' : undefined}
    tabindex={interactive ? 0 : undefined}
    aria-label={interactive ? `${TYPE_LABEL[layer.type]}: ${layerLabel(layer)}` : undefined}
>
    {#if layer.type === 'text'}
        {layer.text}
    {:else if layer.type === 'button'}
        {layer.label}<span aria-hidden="true">&rarr;</span>
    {:else if layer.type === 'badge'}
        {layer.text}
    {:else if layer.type === 'image'}
        <img
            src={layer.src}
            alt=""
            draggable="false"
            class="pointer-events-none h-full w-full object-contain {RADIUS_CLASS[layer.radius]}"
        />
    {/if}
</div>
