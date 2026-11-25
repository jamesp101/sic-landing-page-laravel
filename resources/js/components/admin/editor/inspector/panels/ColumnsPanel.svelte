<script lang="ts">
    import Group from '../Group.svelte';
    import ChoiceField from '../ChoiceField.svelte';
    import { COLUMN_COUNT_OPTIONS, GAP_OPTIONS, RATIO_OPTIONS } from '../options';
    import type { PanelProps } from '../panel';

    let { block, set, fid }: PanelProps<'columns'> = $props();
</script>

<Group title="Layout">
    <ChoiceField
        id={fid('count')}
        label="Number of columns"
        value={block.props.count}
        options={COLUMN_COUNT_OPTIONS}
        onchange={(v) => set({ count: Number(v) })}
    />
    <ChoiceField
        id={fid('ratio')}
        label="Column widths"
        value={block.props.ratio}
        options={RATIO_OPTIONS}
        hint="Unequal widths apply to two columns only."
        onchange={(v) => set({ ratio: v })}
    />
    <ChoiceField
        id={fid('gap')}
        label="Gap"
        value={block.props.gap}
        options={GAP_OPTIONS}
        onchange={(v) => set({ gap: v })}
    />
</Group>
