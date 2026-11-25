<script lang="ts">
    import ColorField from '../ColorField.svelte';
    import ContrastNote from '../ContrastNote.svelte';
    import Group from '../Group.svelte';
    import RichTextField from '../RichTextField.svelte';
    import ChoiceField from '../ChoiceField.svelte';
    import { TEXT_ALIGN_OPTIONS, TEXT_SIZE_OPTIONS } from '../options';
    import type { PanelProps } from '../panel';

    let { block, set, fid, errors }: PanelProps<'paragraph'> = $props();

    // Older blocks keep their colour in `tone`; the colour field shows it and replaces it.
    const textColor = $derived(block.props.color || (block.props.tone === 'muted' ? 'muted' : ''));
</script>

<Group title="Content">
    <RichTextField
        id={fid('text')}
        label="Text"
        value={block.props.text}
        hint="A blank line starts a new paragraph."
        oninput={(v) => set({ text: v })}
    />
</Group>
<Group title="Appearance">
    <ChoiceField
        id={fid('size')}
        label="Text size"
        value={block.props.size}
        options={TEXT_SIZE_OPTIONS}
        onchange={(v) => set({ size: v })}
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
        label="Text colour"
        value={textColor}
        error={errors.color}
        onchange={(v) => set({ color: v, tone: 'default' })}
    />
    <ColorField
        id={fid('background')}
        label="Background"
        value={block.props.background}
        defaultLabel="None"
        error={errors.background}
        onchange={(v) => set({ background: v })}
    />
    <ContrastNote
        text={textColor}
        background={block.props.background}
        textFallback="text-foreground"
        backgroundFallback="bg-background"
    />
</Group>
