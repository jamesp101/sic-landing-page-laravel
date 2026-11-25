<script lang="ts">
    import CheckField from '../CheckField.svelte';
    import Group from '../Group.svelte';
    import ChoiceField from '../ChoiceField.svelte';
    import { CROSS_ALIGN_OPTIONS, DIRECTION_OPTIONS, GAP_OPTIONS, JUSTIFY_OPTIONS } from '../options';
    import type { PanelProps } from '../panel';

    let { block, set, fid }: PanelProps<'row'> = $props();
</script>

<Group title="Layout">
    <ChoiceField
        id={fid('direction')}
        label="Direction"
        value={block.props.direction}
        options={DIRECTION_OPTIONS}
        onchange={(v) => set({ direction: v })}
    />
    <ChoiceField
        id={fid('gap')}
        label="Gap"
        value={block.props.gap}
        options={GAP_OPTIONS}
        onchange={(v) => set({ gap: v })}
    />
    <ChoiceField
        id={fid('align')}
        label="Align items"
        value={block.props.align}
        options={CROSS_ALIGN_OPTIONS}
        onchange={(v) => set({ align: v })}
    />
    <ChoiceField
        id={fid('justify')}
        label="Justify"
        value={block.props.justify}
        options={JUSTIFY_OPTIONS}
        onchange={(v) => set({ justify: v })}
    />
    <CheckField id={fid('wrap')} label="Wrap items" checked={block.props.wrap} onchange={(v) => set({ wrap: v })} />
</Group>
