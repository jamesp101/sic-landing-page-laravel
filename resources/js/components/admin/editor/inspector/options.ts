/**
 * Option lists for the inspector choices (labels are human wording; values match `src/lib/blocks/types.ts`).
 * `ChoiceField` prints each option as a button showing its `icon`, else its `swatch`, else its `short` text (else its
 * label); the full `label` is the button's accessible name and tooltip.
 */
import {
    AlignCenterHorizontal,
    AlignEndHorizontal,
    AlignHorizontalJustifyCenter,
    AlignHorizontalJustifyEnd,
    AlignHorizontalJustifyStart,
    AlignHorizontalSpaceBetween,
    AlignStartHorizontal,
    AArrowDown,
    AArrowUp,
    CircleCheck,
    CircleDashed,
    Columns2,
    Heading2,
    Heading3,
    Heading4,
    Info,
    MonitorSmartphone,
    PanelLeft,
    PanelRight,
    Rows2,
    StretchHorizontal,
    TextAlignCenter,
    TextAlignEnd,
    TextAlignJustify,
    TextAlignStart,
    TriangleAlert,
    Type,
} from '@lucide/svelte';

export interface Option {
    value: string | number;
    label: string;
    icon?: typeof Info;
    /** Classes of a small sized sample of the option (a colour, a line, a button style). */
    swatch?: string;
    /** Button text when there is no icon or swatch. */
    short?: string;
}

export const GAP_OPTIONS: Option[] = [
    { value: 'none', label: 'None', short: '0' },
    { value: 'sm', label: 'Small', short: 'S' },
    { value: 'md', label: 'Medium', short: 'M' },
    { value: 'lg', label: 'Large', short: 'L' },
];

export const ALIGN_OPTIONS: Option[] = [
    { value: 'inherit', label: 'Inherit', icon: CircleDashed },
    { value: 'left', label: 'Left', icon: TextAlignStart },
    { value: 'center', label: 'Centre', icon: TextAlignCenter },
    { value: 'right', label: 'Right', icon: TextAlignEnd },
];

/** Text blocks also justify; boxes (Button) use `ALIGN_OPTIONS`. */
export const TEXT_ALIGN_OPTIONS: Option[] = [
    ...ALIGN_OPTIONS,
    { value: 'justify', label: 'Justify', icon: TextAlignJustify },
];

/** Swatches use the classes the Section prints for each background. */
export const BACKGROUND_OPTIONS: Option[] = [
    { value: 'none', label: 'None', swatch: 'size-5 rounded border border-dashed border-border-strong' },
    { value: 'surface', label: 'Surface', swatch: 'size-5 rounded border border-border-strong bg-surface' },
    { value: 'alt', label: 'Soft', swatch: 'size-5 rounded border border-border-strong bg-surface-alt' },
    { value: 'accent', label: 'Accent', swatch: 'size-5 rounded border border-border-strong bg-accent' },
    { value: 'gold', label: 'Gold', swatch: 'size-5 rounded border border-gold-500 bg-gold-500/10' },
];

export const DIRECTION_OPTIONS: Option[] = [
    { value: 'row', label: 'Row', icon: Columns2 },
    { value: 'column', label: 'Column', icon: Rows2 },
    { value: 'responsive', label: 'Stack on mobile, row on desktop', icon: MonitorSmartphone },
];

export const CROSS_ALIGN_OPTIONS: Option[] = [
    { value: 'start', label: 'Start', icon: AlignStartHorizontal },
    { value: 'center', label: 'Centre', icon: AlignCenterHorizontal },
    { value: 'end', label: 'End', icon: AlignEndHorizontal },
    { value: 'stretch', label: 'Stretch', icon: StretchHorizontal },
];

export const JUSTIFY_OPTIONS: Option[] = [
    { value: 'start', label: 'Start', icon: AlignHorizontalJustifyStart },
    { value: 'center', label: 'Centre', icon: AlignHorizontalJustifyCenter },
    { value: 'end', label: 'End', icon: AlignHorizontalJustifyEnd },
    { value: 'between', label: 'Space between', icon: AlignHorizontalSpaceBetween },
];

const counts = (...ns: number[]): Option[] =>
    ns.map((n) => ({ value: n, label: n === 1 ? '1 column' : `${n} columns`, short: String(n) }));

export const GRID_COLUMN_OPTIONS: Option[] = counts(1, 2, 3, 4, 5, 6);
export const COLUMN_COUNT_OPTIONS: Option[] = counts(2, 3);
export const ALBUM_COLUMN_OPTIONS: Option[] = counts(2, 3, 4);

export const RATIO_OPTIONS: Option[] = [
    { value: 'equal', label: 'Equal', icon: Columns2 },
    { value: 'wide-left', label: 'Wide left', icon: PanelRight },
    { value: 'wide-right', label: 'Wide right', icon: PanelLeft },
];

export const SPACER_OPTIONS: Option[] = [
    { value: 'sm', label: 'Small', short: 'S' },
    { value: 'md', label: 'Medium', short: 'M' },
    { value: 'lg', label: 'Large', short: 'L' },
    { value: 'xl', label: 'Extra large', short: 'XL' },
];

/** Swatches are short rules in the Divider's colours. */
export const DIVIDER_OPTIONS: Option[] = [
    { value: 'default', label: 'Default', swatch: 'h-0 w-5 border-t-2 border-border' },
    { value: 'strong', label: 'Strong', swatch: 'h-0 w-5 border-t-2 border-border-strong' },
    { value: 'gold', label: 'Gold', swatch: 'h-0 w-5 border-t-2 border-gold-500' },
];

export const HEADING_LEVEL_OPTIONS: Option[] = [
    { value: 2, label: 'Heading 2 (section)', icon: Heading2 },
    { value: 3, label: 'Heading 3 (subsection)', icon: Heading3 },
    { value: 4, label: 'Heading 4 (minor)', icon: Heading4 },
];

export const TEXT_SIZE_OPTIONS: Option[] = [
    { value: 'sm', label: 'Small', icon: AArrowDown },
    { value: 'base', label: 'Normal', icon: Type },
    { value: 'lg', label: 'Large', icon: AArrowUp },
];

/** Swatches are small buttons in each variant's look. */
export const BUTTON_VARIANT_OPTIONS: Option[] = [
    { value: 'primary', label: 'Primary', swatch: 'h-3 w-6 rounded-sm bg-brand-800 dark:bg-gold-500' },
    { value: 'secondary', label: 'Secondary', swatch: 'h-3 w-6 rounded-sm border border-border-strong bg-surface' },
    { value: 'ghost', label: 'Ghost', swatch: 'h-3 w-6 rounded-sm border border-dashed border-link' },
];

export const CALLOUT_TONE_OPTIONS: Option[] = [
    { value: 'info', label: 'Information', icon: Info },
    { value: 'success', label: 'Success', icon: CircleCheck },
    { value: 'warning', label: 'Warning', icon: TriangleAlert },
];

export const IMAGE_ACCEPT = 'image/png,image/jpeg,image/webp,image/gif,image/avif';
export const VIDEO_ACCEPT = 'video/mp4,video/webm,.mp4,.webm';
