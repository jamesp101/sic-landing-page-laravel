<script lang="ts">
    import ColorField from '../ColorField.svelte';
    import ContrastNote from '../ContrastNote.svelte';
    import Group from '../Group.svelte';
    import ChoiceField from '../ChoiceField.svelte';
    import TextField from '../TextField.svelte';
    import { LIMITS } from '@/lib/blocks/types';
    import { HEADING_LEVEL_OPTIONS, TEXT_ALIGN_OPTIONS } from '../options';
    import type { PanelProps } from '../panel';

    let { block, set, fid, errors }: PanelProps<'heading'> = $props();
</script>

<Group title="Content">
    <TextField
        id={fid('text')}
        label="Heading text"
        value={block.props.text}
        maxlength={LIMITS.label}
        oninput={(v) => set({ text: v })}
    />
</Group>
<Group title="Appearance">
    <ChoiceField
        id={fid('level')}
        label="Heading level"
        value={block.props.level}
        options={HEADING_LEVEL_OPTIONS}
        onchange={(v) => set({ level: Number(v) })}
    />
    <ChoiceField
        id={fid('align')}
        label="Text alignment"
        value={block.props.align}
        options={TEXT_ALIGN_OPTIONS}
        onchange={(v) => set({ align: v })}
    />
    <ColorField
        id={fid('color')}
        label="Heading colour"
        value={block.props.color}
        error={errors.color}
        onchange={(v) => set({ color: v })}
    />
    <ContrastNote
        text={block.props.color}
        background=""
        textFallback="text-primary"
        backgroundFallback="bg-background"
    />
</Group>
