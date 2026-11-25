<script lang="ts">
    import Group from '../Group.svelte';
    import ChoiceField from '../ChoiceField.svelte';
    import { SPACER_OPTIONS } from '../options';
    import type { PanelProps } from '../panel';

    let { block, set, fid }: PanelProps<'spacer'> = $props();
</script>

<Group title="Spacing">
    <ChoiceField
        id={fid('size')}
        label="Size"
        value={block.props.size}
        options={SPACER_OPTIONS}
        onchange={(v) => set({ size: v })}
    />
</Group>
