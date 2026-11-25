<script lang="ts">
    import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
    import type { Option } from './options';

    /**
     * One choice of a few options, printed as a row of buttons (a radio group): each shows the option's icon, swatch
     * or short text, and the full label is its accessible name and the hover (and focus) label. Arrow keys move and
     * pick, like radios.
     */
    let {
        id,
        label,
        value,
        options,
        onchange,
        hint = '',
    }: {
        id: string;
        label: string;
        value: string | number;
        options: Option[];
        /** Receives the raw option value as a string; callers convert numeric options. */
        onchange: (value: string) => void;
        hint?: string;
    } = $props();

    const labelId = $derived(`${id}-label`);
    const hintId = $derived(`${id}-hint`);
    // The tab stop is the picked option, or the first one when the value matches none.
    const current = $derived(
        Math.max(
            0,
            options.findIndex((option) => String(option.value) === String(value)),
        ),
    );

    let group: HTMLDivElement;

    function keydown(event: KeyboardEvent, index: number) {
        const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];
        const target =
            event.key === 'Home'
                ? 0
                : event.key === 'End'
                  ? options.length - 1
                  : step
                    ? (index + step + options.length) % options.length
                    : null;
        if (target === null) return;
        event.preventDefault();
        onchange(String(options[target].value));
        group.querySelectorAll<HTMLButtonElement>('[role="radio"]')[target]?.focus();
    }
</script>

<div class="space-y-1.5">
    <p id={labelId} class="text-sm font-semibold text-foreground">{label}</p>
    <div
        bind:this={group}
        {id}
        role="radiogroup"
        aria-labelledby={labelId}
        aria-describedby={hint ? hintId : undefined}
        class="flex flex-wrap gap-1 rounded-lg border border-border bg-surface-alt p-1"
    >
        <TooltipProvider delayDuration={150}>
            {#each options as option, index (option.value)}
                {@const checked = index === current && String(option.value) === String(value)}
                <Tooltip>
                    <TooltipTrigger
                        type="button"
                        role="radio"
                        aria-checked={checked}
                        aria-label={option.label}
                        tabindex={index === current ? 0 : -1}
                        onclick={() => onchange(String(option.value))}
                        onkeydown={(event: KeyboardEvent) => keydown(event, index)}
                        class={`flex h-8 min-w-8 flex-1 items-center justify-center rounded-md px-2 text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none ${
                            checked
                                ? 'bg-surface text-foreground shadow-brand-sm ring-1 ring-border-strong'
                                : 'text-muted-foreground hover:bg-surface/70 hover:text-foreground'
                        }`}
                    >
                        {#if option.icon}
                            <option.icon class="h-4 w-4" aria-hidden="true" />
                        {:else if option.swatch}
                            <span class={['block', option.swatch]} aria-hidden="true"></span>
                        {:else}
                            <span aria-hidden="true">{option.short ?? option.label}</span>
                        {/if}
                    </TooltipTrigger>
                    <TooltipContent side="top">{option.label}</TooltipContent>
                </Tooltip>
            {/each}
        </TooltipProvider>
    </div>
    {#if hint}<p id={hintId} class="text-xs text-muted-foreground">{hint}</p>{/if}
</div>
