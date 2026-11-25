<script lang="ts">
    import ColorField from '../ColorField.svelte';
    import ContrastNote from '../ContrastNote.svelte';
    import Group from '../Group.svelte';
    import ChoiceField from '../ChoiceField.svelte';
    import { BACKGROUND_OPTIONS, GAP_OPTIONS, TEXT_ALIGN_OPTIONS } from '../options';
    import type { PanelProps } from '../panel';

    let { block, set, fid, errors }: PanelProps<'section'> = $props();
</script>

<Group title="Appearance">
    <ColorField
        id={fid('backgroundColor')}
        label="Background colour"
        value={block.props.backgroundColor}
        defaultLabel="Preset"
        error={errors.backgroundColor}
        onchange={(v) => set({ backgroundColor: v })}
    />
    {#if block.props.backgroundColor}
        <ContrastNote
            text=""
            background={block.props.backgroundColor}
            textFallback="text-foreground"
            backgroundFallback="bg-surface-alt"
        />
    {:else}
        <ChoiceField
            id={fid('background')}
            label="Background preset"
            value={block.props.background}
            options={BACKGROUND_OPTIONS}
            onchange={(v) => set({ background: v })}
        />
    {/if}
    <ChoiceField
        id={fid('padding')}
        label="Padding"
        value={block.props.padding}
        options={GAP_OPTIONS}
        onchange={(v) => set({ padding: v })}
    />
    <ChoiceField
        id={fid('align')}
        label="Text alignment"
        value={block.props.align}
        options={TEXT_ALIGN_OPTIONS}
        onchange={(v) => set({ align: v })}
    />
</Group>
