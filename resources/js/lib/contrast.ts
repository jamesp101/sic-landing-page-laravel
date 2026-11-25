/** WCAG contrast between two computed CSS colours (`rgb(…)` / `rgba(…)`, as `getComputedStyle` reports them). */

export type Rgba = [number, number, number, number];

/** Parses `rgb(r, g, b)`, `rgba(r, g, b, a)` and `rgb(r g b / a)`; `null` for any other syntax. */
export function parseRgb(css: string): Rgba | null {
    const match = /^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)$/.exec(css.trim());
    if (!match) return null;
    return [Number(match[1]), Number(match[2]), Number(match[3]), parseAlpha(match[4])];
}

function parseAlpha(text: string | undefined): number {
    if (text === undefined) return 1;
    return text.endsWith('%') ? Number(text.slice(0, -1)) / 100 : Number(text);
}

function luminance([r, g, b]: Rgba): number {
    const channel = (value: number) => {
        const c = value / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

/** Contrast ratio (1–21) of two opaque colours. */
export function contrastRatio(a: Rgba, b: Rgba): number {
    const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (light + 0.05) / (dark + 0.05);
}

/** WCAG AA minimum for body text. */
export const MIN_TEXT_CONTRAST = 4.5;
