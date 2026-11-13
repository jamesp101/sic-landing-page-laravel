import { describe, expect, test } from 'vitest';
import { heroImageSizes, HERO_PHONE_IMAGE_WIDTH } from '../carousel-frame';
import { layerPresentation, type LayerPresentation, type SlideMode } from '../carousel-layout';
import {
    legacyFields,
    legacyLayers,
    normalizeSize,
    safeHref,
    safeImageSrc,
    serializeOverlay,
    type Layer,
    type ScaleKind,
    type SlideMobile,
} from '../carousel-overlay';
import { countLines, resolveMobile, slideFocus, type MobileBox } from '../carousel-overlay-mobile';
import {
    parseOverlayDocument,
    parseOverlayStrict,
    slideDocument,
    type OverlayDocument,
    type OverlayResult,
} from '../carousel-overlay-schema';
import { fixture } from './fixtures';

const rows = <T>(cases: readonly T[]): [string, T][] =>
    cases.map((c, i): [string, T] => [`${i} ${JSON.stringify(c).slice(0, 120)}`, c]);

interface OverlayCase {
    raw?: string;
    strict: OverlayResult;
    strictSerialized?: string;
    document: OverlayDocument | null;
    documentSerialized?: string;
}
interface OverlayFixture {
    cases: Record<string, OverlayCase>;
    hrefs: { input: string; href: string | null; image: string | null }[];
    sizes: { kind: ScaleKind; size: unknown; out: string }[];
    legacy: { row: Parameters<typeof legacyLayers>[0]; layers: Layer[] }[];
    fields: { layers: Layer[]; out: ReturnType<typeof legacyFields> }[];
    slideDocs: { row: Parameters<typeof slideDocument>[0]; doc: OverlayDocument }[];
}
interface PresentationCase {
    id: string;
    mode: SlideMode | 'public';
    z: number;
    box: MobileBox | null;
    out: LayerPresentation & { phoneClass: string };
}
interface LayerSet {
    layers: Layer[];
    boxes: [string, MobileBox][];
    presentations: PresentationCase[];
}
interface LayoutFixture {
    sets: Record<string, LayerSet>;
    countLines: { text: string; cap: number; out: number }[];
    sizes: { tall: string; short: string; width: typeof HERO_PHONE_IMAGE_WIDTH };
    focus: [{ x: number; y: number }, { x: number; y: number }];
}
interface SlideView {
    row: Parameters<typeof slideDocument>[0] & { id: number };
    designer: { layers: Layer[]; mobile: SlideMobile | null };
    /** The public Slide's `stage` (Layers presented by `Layout::stage`) is PHP-only; only the focal point is shared. */
    public: { focus: { x: number; y: number } };
}

// Mirrors tests/Unit/Carousel/OverlayTest.php.
describe('overlay.json', () => {
    const overlay = fixture<OverlayFixture>('Carousel', 'overlay');

    test.each(Object.entries(overlay.cases))('case %s', (name, c) => {
        const raw = name === 'oversize' ? `{"v":1,"layers":[],"pad":"${'x'.repeat(41000)}"}` : c.raw!;

        const strict = parseOverlayStrict(raw);
        expect(strict, `strict: ${name}`).toEqual(c.strict);
        if (strict.ok) expect(serializeOverlay(strict.layers, strict.mobile)).toBe(c.strictSerialized);

        const document = parseOverlayDocument(raw);
        expect(document, `document: ${name}`).toEqual(c.document);
        if (document !== null) expect(serializeOverlay(document.layers, document.mobile)).toBe(c.documentSerialized);
    });

    test.each(rows(overlay.hrefs))('href %s', (_n, c) => {
        expect(safeHref(c.input)).toBe(c.href);
        expect(safeImageSrc(c.input)).toBe(c.image);
    });

    test.each(rows(overlay.sizes))('size %s', (_n, c) => {
        expect(normalizeSize(c.kind, c.size)).toBe(c.out);
    });

    test.each(rows(overlay.legacy))('legacy layers %s', (_n, c) => {
        expect(legacyLayers(c.row ?? {})).toEqual(c.layers);
    });

    test.each(rows(overlay.fields))('legacy fields %s', (_n, c) => {
        expect(legacyFields(c.layers)).toEqual(c.out);
    });

    test.each(rows(overlay.slideDocs))('slide document %s', (_n, c) => {
        expect(slideDocument(c.row)).toEqual(c.doc);
    });
});

// Mirrors tests/Unit/Carousel/OverlayMobileTest.php and LayoutTest.php.
describe('layout.json', () => {
    const layout = fixture<LayoutFixture>('Carousel', 'layout');

    describe.each(Object.entries(layout.sets))('set %s', (_name, set) => {
        test('phone boxes (resolveMobile)', () => {
            const boxes = resolveMobile(set.layers);
            expect([...boxes.entries()]).toEqual(set.boxes);
        });

        test('layer presentations', () => {
            const layers = new Map(set.layers.map((l): [string, Layer] => [l.id, l]));
            expect(set.presentations).toHaveLength(set.layers.length * 3);
            // `public` presentations are PHP-only (`Layout::stage`); the designer previews are shared. `phoneClass` is public-only.
            for (const { mode, ...c } of set.presentations) {
                if (mode === 'public') continue;
                const { phoneClass, ...out } = c.out;
                expect(phoneClass).toBe('');
                expect(layerPresentation(layers.get(c.id)!, c.z, c.box, mode), `${c.id} ${mode}`).toEqual(out);
            }
        });
    });

    test.each(rows(layout.countLines))('countLines %s', (_n, c) => {
        expect(countLines(c.text, c.cap)).toBe(c.out);
    });

    test('slideFocus', () => {
        const [centred, set] = layout.focus;
        expect(slideFocus(null)).toEqual(centred);
        expect(slideFocus({ focus: { x: 20, y: 30 } })).toEqual(set);
    });

    test('hero image sizes', () => {
        expect(heroImageSizes(true)).toBe(layout.sizes.tall);
        expect(heroImageSizes(false)).toBe(layout.sizes.short);
        expect(HERO_PHONE_IMAGE_WIDTH).toEqual(layout.sizes.width);
    });
});

// Mirrors tests/Unit/Carousel/SlidesTest.php. The Slide read model (`toDesignerSlide`, `findSlides`, `parseSlideVideo`)
// needs database rows and file storage and only exists in PHP; what is pure is the Layers/phone settings of a row.
describe('slides.json (pure part: slideDocument)', () => {
    const slides = fixture<{ views: SlideView[] }>('Carousel', 'slides');

    test.each(slides.views.map((v): [string, SlideView] => [`slide ${v.row.id}`, v]))('%s', (_n, view) => {
        const doc = slideDocument(view.row);
        expect(doc.layers).toEqual(view.designer.layers);
        expect(doc.mobile).toEqual(view.designer.mobile);
        expect(slideFocus(doc.mobile)).toEqual(view.public.focus);
    });
});
