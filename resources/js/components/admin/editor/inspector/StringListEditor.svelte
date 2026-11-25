<script lang="ts">
    import { tick } from 'svelte';
    import { pastedItems } from '@/lib/blocks/inline-edit';
    import { inputClass } from '../../shared/form-utils';
    import AddButton from './AddButton.svelte';
    import ItemToolbar from './ItemToolbar.svelte';
    import { moveItem, removeItem, replaceItem, spliceItems, type Splice } from './list-utils';

    let {
        idBase,
        legend,
        noun,
        items,
        max,
        onchange,
        maxlength = 300,
    }: {
        idBase: string;
        legend: string;
        /** Lower-case singular noun: `item`, `badge`. */
        noun: string;
        items: string[];
        max: number;
        onchange: (items: string[]) => void;
        maxlength?: number;
    } = $props();

    const title = $derived(noun.charAt(0).toUpperCase() + noun.slice(1));

    /** Applies a split or join and puts the caret into item `caret.index` after `caret.offset` characters. */
    async function splice(change: Splice<string>, caret: { index: number; offset: number }) {
        const next = spliceItems(items, change, max);
        if (!next) return;
        onchange(next);
        await tick();
        const input = document.getElementById(`${idBase}-${caret.index}`);
        if (!(input instanceof HTMLInputElement)) return;
        input.focus();
        input.setSelectionRange(caret.offset, caret.offset);
    }

    function textAround(input: HTMLInputElement) {
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? start;
        return { before: input.value.slice(0, start), after: input.value.slice(end), atStart: end === 0 };
    }

    /** Enter starts a new item after the caret; Backspace at the start joins the item to the one above. */
    function onkeydown(event: KeyboardEvent & { currentTarget: HTMLInputElement }, index: number) {
        if (event.isComposing || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
        const { before, after, atStart } = textAround(event.currentTarget);
        if (event.key === 'Enter') {
            event.preventDefault();
            void splice(
                { start: index, remove: 1, insert: [before.trimEnd(), after.trimStart()] },
                { index: index + 1, offset: 0 },
            );
        } else if (event.key === 'Backspace' && atStart && index > 0) {
            event.preventDefault();
            const previous = items[index - 1];
            void splice(
                { start: index - 1, remove: 2, insert: [previous + event.currentTarget.value] },
                { index: index - 1, offset: previous.length },
            );
        }
    }

    /** Several pasted lines become several items (bullets and numbers dropped). */
    function onpaste(event: ClipboardEvent & { currentTarget: HTMLInputElement }, index: number) {
        const lines = pastedItems(event.clipboardData?.getData('text/plain') ?? '');
        if (lines.length < 2) return;
        const { before, after } = textAround(event.currentTarget);
        // A paste that would overfill the list falls back to the browser's single-line paste.
        if (items.length + lines.length - 1 > max) return;
        event.preventDefault();
        const insert = [...lines];
        insert[0] = before + insert[0];
        insert.push(`${insert.pop() ?? ''}${after}`);
        void splice(
            { start: index, remove: 1, insert },
            { index: index + lines.length - 1, offset: lines.at(-1)?.length ?? 0 },
        );
    }
</script>

<fieldset class="space-y-2">
    <legend class="text-sm font-semibold text-foreground">{legend}</legend>
    {#if items.length === 0}
        <p class="text-xs text-muted-foreground">No {noun}s yet.</p>
    {/if}
    <ul class="space-y-2">
        {#each items as item, index (index)}
            <li class="flex items-center gap-1">
                <input
                    id={`${idBase}-${index}`}
                    type="text"
                    value={item}
                    {maxlength}
                    class={inputClass}
                    aria-label={`${title} ${index + 1}`}
                    oninput={(event) => onchange(replaceItem(items, index, event.currentTarget.value))}
                    onkeydown={(event) => onkeydown(event, index)}
                    onpaste={(event) => onpaste(event, index)}
                />
                <ItemToolbar
                    {noun}
                    n={index + 1}
                    count={items.length}
                    onmove={(delta) => onchange(moveItem(items, index, index + delta))}
                    onremove={() => onchange(removeItem(items, index))}
                />
            </li>
        {/each}
    </ul>
    <AddButton label={`Add ${noun}`} disabled={items.length >= max} onclick={() => onchange([...items, ''])} />
    {#if items.length >= max}<p class="text-xs text-muted-foreground">Maximum of {max} {noun}s reached.</p>{/if}
</fieldset>
