/**
 * Block colours. A stored colour is `''` (the block's own colour), a Brand palette name (printed as design-token
 * classes, so it follows dark mode) or a custom `#rrggbb` (printed as an inline style). Twin: `App\Content\Blocks\Colors`.
 */
import type { Report } from './block-type';

export interface PaletteColor {
    label: string;
    text: string;
    bg: string;
}

/** Tailwind finds these class names by scanning. */
export const COLOR_PALETTE = {
    primary: { label: 'Primary', text: 'text-primary', bg: 'bg-primary' },
    navy: { label: 'Navy', text: 'text-brand-800', bg: 'bg-brand-800' },
    'navy-deep': { label: 'Deep navy', text: 'text-brand-950', bg: 'bg-brand-950' },
    blue: { label: 'Blue', text: 'text-brand-600', bg: 'bg-brand-600' },
    sky: { label: 'Sky', text: 'text-brand-100', bg: 'bg-brand-100' },
    'sky-soft': { label: 'Soft sky', text: 'text-brand-50', bg: 'bg-brand-50' },
    gold: { label: 'Gold', text: 'text-gold-500', bg: 'bg-gold-500' },
    'gold-light': { label: 'Light gold', text: 'text-gold-300', bg: 'bg-gold-300' },
    ink: { label: 'Ink', text: 'text-foreground', bg: 'bg-foreground' },
    muted: { label: 'Muted', text: 'text-muted-foreground', bg: 'bg-muted-foreground' },
    surface: { label: 'White', text: 'text-surface', bg: 'bg-surface' },
    soft: { label: 'Soft grey', text: 'text-surface-alt', bg: 'bg-surface-alt' },
} as const satisfies Record<string, PaletteColor>;

export type PaletteName = keyof typeof COLOR_PALETTE;

const HEX = /^#[0-9a-fA-F]{6}$/;

export const COLOR_MESSAGE = 'Pick a palette colour or enter a hex colour such as #0a2e6e.';

export function isPaletteColor(value: unknown): value is PaletteName {
    return typeof value === 'string' && Object.hasOwn(COLOR_PALETTE, value);
}

export function isHexColor(value: unknown): value is string {
    return typeof value === 'string' && HEX.test(value);
}

/** `''`, a palette name or `#rrggbb`. */
export function isColor(value: unknown): boolean {
    return value === '' || isPaletteColor(value) || isHexColor(value);
}

/** Reports the colour props that are neither empty, a palette name nor a hex colour. */
export function checkColors<P>(props: P, keys: (keyof P & string)[], report: Report): void {
    for (const key of keys) {
        if (!isColor(props[key])) report(COLOR_MESSAGE, [key]);
    }
}

/** How a colour prints: a class or a style declaration; `null` for no (or an invalid) colour. */
export interface ColorParts {
    className: string;
    style: string;
}

export function colorParts(value: unknown, kind: 'text' | 'bg'): ColorParts | null {
    if (isPaletteColor(value)) return { className: COLOR_PALETTE[value][kind], style: '' };
    if (isHexColor(value)) {
        return { className: '', style: `${kind === 'text' ? 'color' : 'background-color'}:${value.toLowerCase()}` };
    }
    return null;
}

/** ` style="…"` from the given colour parts, `''` when none of them is a custom colour. */
export function styleAttr(...parts: (ColorParts | null)[]): string {
    const style = parts
        .map((part) => part?.style ?? '')
        .filter(Boolean)
        .join(';');
    return style ? ` style="${style}"` : '';
}
