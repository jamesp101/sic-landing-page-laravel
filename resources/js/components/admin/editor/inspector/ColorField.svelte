<script lang="ts">
    import { inputClass } from '../../shared/form-utils';
    import { COLOR_PALETTE, isHexColor, isPaletteColor, type PaletteName } from '@/lib/blocks/colors';

    /** A Block colour: Default, a Brand palette swatch or a custom hex colour (`lib/blocks/colors.ts`). */
    let {
        id,
        label,
        value,
        onchange,
        defaultLabel = 'Default',
        error = '',
    }: {
        id: string;
        label: string;
        value: string;
        onchange: (value: string) => void;
        defaultLabel?: string;
        error?: string;
    } = $props();

    const names = Object.keys(COLOR_PALETTE) as PaletteName[];
    const custom = $derived(isHexColor(value) ? value.toLowerCase() : '');
    // What the custom box shows while the editor types an unfinished hex value.
    let draft = $state('');
    $effect(() => {
        draft = custom;
    });

    const swatchClass =
        'size-7 rounded-full border border-border-strong focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none aria-pressed:ring-2 aria-pressed:ring-ring aria-pressed:ring-offset-2 aria-pressed:ring-offset-surface';

    function typed(text: string) {
        draft = text;
        const hex = text.startsWith('#') ? text : `#${text}`;
        if (isHexColor(hex)) onchange(hex.toLowerCase());
        else if (text === '') onchange('');
    }
</script>

<fieldset class="space-y-2" aria-describedby={error ? `${id}-error` : undefined}>
    <legend class="text-sm font-semibold text-foreground">{label}</legend>
    <div class="flex flex-wrap gap-2">
        <button
            type="button"
            class={[swatchClass, 'relative overflow-hidden bg-surface']}
            aria-pressed={value === '' || !(isPaletteColor(value) || isHexColor(value))}
            aria-label={defaultLabel}
            title={defaultLabel}
            onclick={() => onchange('')}
        >
            <span class="absolute inset-x-0 top-1/2 h-px -rotate-45 bg-destructive" aria-hidden="true"></span>
        </button>
        {#each names as name (name)}
            <button
                type="button"
                class={[swatchClass, COLOR_PALETTE[name].bg]}
                aria-pressed={value === name}
                aria-label={COLOR_PALETTE[name].label}
                title={COLOR_PALETTE[name].label}
                onclick={() => onchange(name)}
            ></button>
        {/each}
    </div>
    <div class="flex items-center gap-2">
        <input
            id={`${id}-picker`}
            type="color"
            value={custom || '#0a2e6e'}
            aria-label={`${label}: custom colour`}
            class="h-9 w-12 cursor-pointer rounded-md border border-border-strong bg-surface p-1"
            oninput={(event) => onchange(event.currentTarget.value.toLowerCase())}
        />
        <input
            {id}
            type="text"
            value={draft}
            maxlength={7}
            placeholder="#0a2e6e"
            aria-label={`${label}: hex value`}
            aria-invalid={error ? true : undefined}
            class={[inputClass, 'font-mono']}
            oninput={(event) => typed(event.currentTarget.value.trim())}
        />
    </div>
    {#if error}<p id={`${id}-error`} class="text-xs text-destructive" role="alert">{error}</p>{/if}
</fieldset>
