<script lang="ts">
    import { RADIUS_CLASS, TYPE_LABEL, layerLabel, relFor, type Layer } from '@/lib/carousel-overlay';
    import { layerClasses, layerPresentation, type SlideMode } from '@/lib/carousel-layout';
    import type { MobileBox } from '@/lib/carousel-overlay-mobile';

    let {
        layer,
        z,
        mode = 'public',
        interactive = false,
        box = null,
    }: {
        layer: Layer;
        /** Stacking order (1 = bottom). */
        z: number;
        /** `public` renders real elements (headings, links); the designer modes render inert, focusable boxes. */
        mode?: SlideMode;
        interactive?: boolean;
        /** Phone box of the layer (resolveMobile). */
        box?: MobileBox | null;
    } = $props();

    const preview = $derived(mode !== 'public');
    const look = $derived(layerPresentation(layer, z, box, mode));
    const style = $derived(look.style);
    const className = $derived(layerClasses(layer, look, preview));
</script>

{#if preview}
    <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
    <div
        class="{className} cursor-grab select-none {look.ghostClass}"
        {style}
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
{:else if layer.type === 'text'}
    {#if layer.role === 'heading'}
        <h2 class={className} {style}>{layer.text}</h2>
    {:else}
        <p class={className} {style}>{layer.text}</p>
    {/if}
{:else if layer.type === 'button'}
    <a href={layer.href} rel={relFor(layer.href)} class={className} {style}>
        {layer.label}<span aria-hidden="true">&rarr;</span>
    </a>
{:else if layer.type === 'badge'}
    <span class={className} {style}>{layer.text}</span>
{:else if layer.type === 'shape'}
    <div class={className} {style} aria-hidden="true"></div>
{:else}
    <img src={layer.src} alt={layer.alt} loading="lazy" decoding="async" class="{className} object-contain" {style} />
{/if}
