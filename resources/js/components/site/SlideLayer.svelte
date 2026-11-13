<script lang="ts">
    import type { LayerElement } from '@/lib/content-types';

    /** One Layer of a public Slide, exactly as the server presents it (`Layout::layerElement`): this only prints it. */
    let { element }: { element: LayerElement } = $props();
</script>

{#if element.tag === 'img'}
    <img {...element.attrs} alt={element.attrs.alt} />
{:else}
    <svelte:element this={element.tag} {...element.attrs}>
        {element.text}{#if element.arrow}<span aria-hidden="true">&rarr;</span>{/if}
    </svelte:element>
{/if}
