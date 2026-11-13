import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, test } from 'vitest';
import {
    FRAME_ASPECT_CLASS,
    HERO_HEIGHT,
    HERO_PHONE_IMAGE_WIDTH,
    PHONE_FRAME,
    PHONE_MAX_REM,
    WIDE_MIN_REM,
    heroImageSizes,
} from './carousel-frame';

const src = fileURLToPath(new URL('../../', import.meta.url));
const layoutCss = readFileSync(`${src}css/slide-layout.css`, 'utf8');

function sourceFiles(dir: string): string[] {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const path = `${dir}${entry.name}`;
        if (entry.isDirectory()) return entry.name === 'generated' ? [] : sourceFiles(`${path}/`);
        return /\.(svelte|blade\.php|ts|css)$/.test(entry.name) ? [path] : [];
    });
}

describe('slide-layout.css agrees with the frame module', () => {
    test('phone breakpoint, frame and hero heights', () => {
        expect(layoutCss).toContain(`@media (max-width: ${PHONE_MAX_REM}rem)`);
        expect(layoutCss).toContain(`@container hero (max-width: ${PHONE_MAX_REM}rem)`);
        expect(layoutCss).toContain(`@media (min-width: ${WIDE_MIN_REM}rem)`);
        expect(layoutCss).toContain(`aspect-ratio: ${PHONE_FRAME.w} / ${PHONE_FRAME.h};`);
        expect(FRAME_ASPECT_CLASS.phone).toBe(`aspect-[${PHONE_FRAME.w}/${PHONE_FRAME.h}]`);
        expect(layoutCss).toMatch(new RegExp(`\\.slide-hero \\{[^}]*--hh: ${HERO_HEIGHT.tall.base}px`));
        expect(layoutCss).toMatch(new RegExp(`\\.slide-hero-short \\{\\s*--hh: ${HERO_HEIGHT.short.base}px`));
        expect(layoutCss).toMatch(
            new RegExp(`min-width[^{]*\\{\\s*\\.slide-hero \\{\\s*--hh: ${HERO_HEIGHT.tall.wide}px`),
        );
        expect(layoutCss).toMatch(new RegExp(`\\.slide-hero-short \\{\\s*--hh: ${HERO_HEIGHT.short.wide}px`));
    });

    test('no other source file hard-codes them', () => {
        const owners = ['carousel-frame.ts', 'carousel-frame.test.ts', 'slide-layout.css'];
        const offenders = sourceFiles(src)
            .filter((file) => !owners.some((owner) => file.endsWith(`/${owner}`)))
            .filter((file) =>
                /39\.99|aspect-\[4\/5\]|aspect-ratio: 4 \/ 5|--hh:|@container hero/.test(readFileSync(file, 'utf8')),
            )
            .map((file) => file.slice(src.length));
        expect(offenders).toEqual([]);
    });
});

describe('hero image sizes', () => {
    test('a phone hero draws the 16:9 photo as wide as its height requires', () => {
        // 500 px tall at 16:9 is 888.9 px; 400 px tall is 711.1 px.
        expect(HERO_PHONE_IMAGE_WIDTH).toEqual({ tall: 889, short: 712 });
    });

    test('sizes switch from the phone width to the viewport at the phone breakpoint', () => {
        expect(heroImageSizes(true)).toBe('(max-width: 39.99rem) 889px, 100vw');
        expect(heroImageSizes(false)).toBe('(max-width: 39.99rem) 712px, 100vw');
    });
});
