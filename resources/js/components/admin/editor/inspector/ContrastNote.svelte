<script lang="ts">
    import { colorParts } from '@/lib/blocks/colors';
    import { contrastRatio, MIN_TEXT_CONTRAST, parseRgb } from '@/lib/contrast';

    /**
     * Warns (never blocks) when a block's text colour on its background falls below WCAG AA. Colours are resolved
     * in the browser from the same classes and styles the renderer prints, so palette tokens follow the active theme.
     */
    let {
        text,
        background,
        textFallback,
        backgroundFallback,
    }: {
        /** Block colours (`''`, palette name or `#rrggbb`). */
        text: string;
        background: string;
        /** Classes the renderer prints when the colour is `''`. */
        textFallback: string;
        backgroundFallback: string;
    } = $props();

    let ratio = $state<number | null>(null);

    function resolve(value: string, kind: 'text' | 'bg', fallback: string): string {
        const parts = colorParts(value, kind);
        const probe = document.createElement('span');
        probe.className = parts ? parts.className : fallback;
        if (parts?.style) probe.setAttribute('style', parts.style);
        document.body.append(probe);
        const style = getComputedStyle(probe);
        const resolved = kind === 'text' ? style.color : style.backgroundColor;
        probe.remove();
        return resolved;
    }

    $effect(() => {
        const fg = parseRgb(resolve(text, 'text', textFallback));
        const bg = parseRgb(resolve(background, 'bg', backgroundFallback));
        // Translucent colours depend on what is behind the block, so no reliable figure.
        ratio = fg && bg && fg[3] === 1 && bg[3] === 1 ? contrastRatio(fg, bg) : null;
    });
</script>

<div aria-live="polite">
    {#if ratio !== null && ratio < MIN_TEXT_CONTRAST}
        <p class="rounded-md border border-gold-500/40 bg-gold-500/10 px-3 py-2 text-xs text-foreground">
            Low contrast ({ratio.toFixed(1)}:1). Text may be hard to read; aim for at least {MIN_TEXT_CONTRAST}:1.
        </p>
    {/if}
</div>
