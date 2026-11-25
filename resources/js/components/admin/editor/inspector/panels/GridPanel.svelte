<script lang="ts">
    import Group from '../Group.svelte';
    import ChoiceField from '../ChoiceField.svelte';
    import { GAP_OPTIONS, GRID_COLUMN_OPTIONS } from '../options';
    import type { PanelProps } from '../panel';

    let { block, set, fid }: PanelProps<'grid'> = $props();
</script>

<Group title="Layout">
    <ChoiceField
        id={fid('columns')}
        label="Columns"
        value={block.props.columns}
        options={GRID_COLUMN_OPTIONS}
        onchange={(v) => set({ columns: Number(v) })}
    />
    <ChoiceField
        id={fid('gap')}
        label="Gap"
        value={block.props.gap}
        options={GAP_OPTIONS}
        onchange={(v) => set({ gap: v })}
    />
</Group>
