import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, test } from 'vitest';
import { layerClasses, layerPresentation, measureLayerBox, measureLayerBoxes, type SlideMode } from './carousel-layout';
import { defaultLayer, type Layer } from './carousel-overlay';
import type { MobileBox } from './carousel-overlay-mobile';

const src = fileURLToPath(new URL('../../', import.meta.url));

describe('layerPresentation', () => {
    const heading: Layer = {
        ...defaultLayer('text'),
        id: 'h',
        x: 10,
        y: 20,
        w: 50,
        rotation: 5,
        opacity: 80,
        color: 'navy',
    } as Layer;
    const stacked: MobileBox = {
        x: 6,
        y: 40,
        w: 88,
        h: 10,
        autoW: true,
        autoH: true,
        fs: 7,
        hidden: false,
        auto: true,
    };

    test('a hand-placed layer keeps its dark colour on phones; an automatic one turns white', () => {
        expect(layerPresentation(heading, 1, { ...stacked, auto: false }, 'phone').textColor).toBe('text-brand-800');
        expect(layerPresentation(heading, 1, stacked, 'phone').textColor).toBe('text-white');
    });

    test('the desktop preview ignores the phone box', () => {
        const look = layerPresentation(heading, 1, stacked, 'desktop');
        expect(look.style).not.toContain('--mx');
        expect(look.style).toContain('left:10%');
        expect(look.textColor).toBe('text-brand-800');
    });

    test('the phone preview applies the phone box directly', () => {
        const look = layerPresentation(heading, 1, stacked, 'phone');
        expect(look.style).toBe('z-index:1;left:6%;top:40%;--fs:7');
        expect(look.textColor).toBe('text-white');
        const ghost = layerPresentation(heading, 1, { ...stacked, hidden: true }, 'phone');
        expect(ghost.style).toContain('opacity:0.3');
        expect(ghost.ghostClass).toContain('outline-dashed');
    });
});

describe('layerClasses', () => {
    // Shared with tests/Unit/Carousel/LayoutTest.php: the designer boxes (`preview`) must produce these exact strings.
    // The public cases are PHP-only (`Layout::stage`).
    const { cases } = JSON.parse(readFileSync(`${src}../tests/Unit/Carousel/fixtures/layer-classes.json`, 'utf8')) as {
        cases: { layer: Layer; box: MobileBox | null; mode: SlideMode | 'public'; preview: boolean; out: string }[];
    };
    const designer = cases.filter((c): c is typeof c & { mode: SlideMode } => c.preview);

    test.each(designer.map((c) => [`${c.layer.id} ${c.mode} ${c.box ? JSON.stringify(c.box) : 'no box'}`, c] as const))(
        'matches the shared fixture: %s',
        (_name, c) => {
            expect(layerClasses(c.layer, layerPresentation(c.layer, 1, c.box, c.mode))).toBe(c.out);
        },
    );
});

describe('measuring', () => {
    const el = (left: number, top: number, width: number, height: number, id?: string, parent?: object) =>
        ({
            offsetLeft: left,
            offsetTop: top,
            offsetWidth: width,
            offsetHeight: height,
            dataset: { layerId: id },
            parentElement: parent,
        }) as unknown as HTMLElement;

    test('boxes are percentages of the stage', () => {
        const layer = el(100, 50, 200, 25, 'a');
        const stage = {
            clientWidth: 400,
            clientHeight: 200,
            querySelectorAll: () => [layer],
        } as unknown as HTMLElement;
        expect(measureLayerBoxes(stage)).toEqual({ a: { x: 25, y: 25, w: 50, h: 12.5 } });
        expect(measureLayerBox(el(100, 50, 200, 25, 'a', stage))).toEqual({ x: 25, y: 25, w: 50, h: 12.5 });
    });

    test('a stage without size measures nothing', () => {
        const stage = { clientWidth: 0, clientHeight: 0, querySelectorAll: () => [] } as unknown as HTMLElement;
        expect(measureLayerBoxes(stage)).toBeNull();
        expect(measureLayerBox(el(0, 0, 1, 1, 'a', stage))).toBeNull();
    });
});
