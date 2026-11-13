/**
 * Slide layout: how a Layer is placed and styled in a Slide frame, for the slide designer. The frames themselves
 * live in `carousel-frame.ts`; the public Carousel prints what the server presents (`Layout::stage`).
 */
import type { Box } from './carousel-editor';
import {
    ALIGN_CLASS,
    BG_COLOR_CLASS,
    ON_COLOR_CLASS,
    RADIUS_CLASS,
    TEXT_COLOR_CLASS,
    TYPE_SCALE,
    scaleKind,
    type ColorKey,
    type Layer,
} from './carousel-overlay';
import type { MobileBox } from './carousel-overlay-mobile';

// ---------------------------------------------------------------------------
// Layer presentation
// ---------------------------------------------------------------------------

/**
 * Which layout the designer previews (the public Carousel is presented by the server, `Layout::stage`):
 * - `desktop`: the desktop layout.
 * - `phone`: the phone layout (no container query, so the box is applied directly).
 */
export type SlideMode = 'desktop' | 'phone';

/**
 * Text colours that disappear on the darkened photo of a phone frame. Layers stacked automatically on phones
 * switch these to white; hand-placed layers keep their colour.
 */
const DARK_ON_PHONE: readonly ColorKey[] = ['navy', 'navy-deep', 'foreground', 'surface'];

export interface LayerPresentation {
    /** Inline style: stacking, geometry, opacity, rotation and font size. */
    style: string;
    /** Text colour class for text, outline buttons (empty for images). */
    textColor: string;
    /** Marks a layer that is hidden on phones in the phone preview. */
    ghostClass: string;
}

function textColorFor(layer: Layer, box: MobileBox | null, mode: SlideMode): string {
    if (layer.type === 'image') return '';
    const base = TEXT_COLOR_CLASS[layer.color];
    if (!box?.auto || !DARK_ON_PHONE.includes(layer.color)) return base;
    return mode === 'phone' ? 'text-white' : base;
}

/** Style and classes of one Layer at stacking order `z` (1 = bottom) with its resolved phone `box`. */
export function layerPresentation(layer: Layer, z: number, box: MobileBox | null, mode: SlideMode): LayerPresentation {
    const phonePreview = mode === 'phone' && box !== null;
    const textColor = textColorFor(layer, box, mode);
    const parts = [`z-index:${z}`];

    if (phonePreview) {
        parts.push(`left:${box.x}%`, `top:${box.y}%`);
        if (!box.autoW) parts.push(`width:${box.w}%`);
        if (!box.autoH) parts.push(`height:${box.h}%`);
        if (box.fs !== null) parts.push(`--fs:${box.fs}`);
        // Hidden layers stay reachable in the designer as ghosts; automatic layers are always fully opaque.
        const opacity = box.hidden ? 0.3 : box.auto ? 1 : layer.opacity / 100;
        if (opacity < 1) parts.push(`opacity:${opacity}`);
        return {
            style: parts.join(';'),
            textColor,
            ghostClass: box.hidden ? 'outline-1 outline-dashed outline-white' : '',
        };
    }

    parts.push(`left:${layer.x}%`, `top:${layer.y}%`);
    if ('w' in layer) parts.push(`width:${layer.w}%`);
    if ('h' in layer) parts.push(`height:${layer.h}%`);
    if (layer.opacity < 100) parts.push(`opacity:${layer.opacity / 100}`);
    if (layer.rotation) parts.push(`transform:rotate(${layer.rotation}deg)`);
    const kind = scaleKind(layer);
    if (kind && 'size' in layer) parts.push(`--fs:${TYPE_SCALE[kind][layer.size]}`);
    return { style: parts.join(';'), textColor, ghostClass: '' };
}

/**
 * The class recipe of one Layer's inert designer box, given its `look` (`layerPresentation`): no phone modifiers and
 * no hover lift on buttons. Twin of `Layout::layerClasses` with `$preview` true.
 */
export function layerClasses(layer: Layer, look: LayerPresentation): string {
    switch (layer.type) {
        case 'text':
            return [
                'sl sl-fs sl-text',
                ALIGN_CLASS[layer.align],
                look.textColor,
                'whitespace-pre-line break-words',
                // Serif is reserved for headings (h1/h2) by the design rules.
                layer.role === 'heading' && layer.font === 'serif' ? 'font-serif' : 'font-sans',
                layer.role === 'heading' ? 'font-bold leading-tight tracking-tight' : 'leading-snug',
            ].join(' ');
        case 'button':
            return [
                'sl sl-fs',
                'inline-flex min-w-[10em] items-center justify-center gap-[0.6em] rounded-xl border-2 px-[1.5em] py-[0.8em] font-semibold uppercase tracking-widest leading-none',
                layer.variant === 'solid'
                    ? `border-transparent ${BG_COLOR_CLASS[layer.color]} ${ON_COLOR_CLASS[layer.color]} shadow-brand-md`
                    : `border-current ${look.textColor}`,
            ].join(' ');
        case 'badge':
            return `sl sl-fs inline-block rounded-full px-[0.9em] py-[0.35em] font-semibold uppercase leading-none tracking-wider ${BG_COLOR_CLASS[layer.color]} ${ON_COLOR_CLASS[layer.color]}`;
        case 'shape':
            return `sl sl-shape ${BG_COLOR_CLASS[layer.color]} ${RADIUS_CLASS[layer.radius]}`;
        case 'image':
            return `sl sl-img ${RADIUS_CLASS[layer.radius]}`;
    }
}

// ---------------------------------------------------------------------------
// Measuring
// ---------------------------------------------------------------------------

function boxIn(el: HTMLElement, width: number, height: number): Box {
    return {
        x: (el.offsetLeft / width) * 100,
        y: (el.offsetTop / height) * 100,
        w: (el.offsetWidth / width) * 100,
        h: (el.offsetHeight / height) * 100,
    };
}

/** Rendered box of one layer element in percent of its stage (its parent); null while the stage has no size. */
export function measureLayerBox(el: HTMLElement): Box | null {
    const stage = el.parentElement;
    if (!stage?.clientWidth || !stage.clientHeight) return null;
    return boxIn(el, stage.clientWidth, stage.clientHeight);
}

/** Rendered boxes of every layer in a stage (text height is automatic) by layer id; null while the stage has no size. */
export function measureLayerBoxes(stage: HTMLElement): Record<string, Box> | null {
    const width = stage.clientWidth;
    const height = stage.clientHeight;
    if (!width || !height) return null;
    const boxes: Record<string, Box> = {};
    for (const el of stage.querySelectorAll<HTMLElement>('[data-layer-id]')) {
        boxes[el.dataset.layerId as string] = boxIn(el, width, height);
    }
    return boxes;
}
