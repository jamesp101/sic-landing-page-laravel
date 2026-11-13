import { expect, test, type Browser, type Page } from '@playwright/test';

import { DEMO_DEPARTMENTS } from './demo-data';

const DESKTOP = { width: 1280, height: 800 };
const PHONE = { width: 390, height: 844 };

/** The header menu as the visitor sees it: nav, toggle, each top link and group trigger (box, text, current marking, colour). */
async function menuLayout(page: Page) {
    return page.evaluate(() => {
        const box = (el: Element) => {
            const r = el.getBoundingClientRect();
            return [
                Math.round(r.x - origin.x),
                Math.round(r.y - origin.y),
                Math.round(r.width),
                Math.round(r.height),
            ].join(',');
        };
        const text = (el: Element) => (el.textContent ?? '').replace(/\s+/g, ' ').trim();
        const nav = document.querySelector('[data-site-header] nav[aria-label="Primary"]')!;
        // Relative to the nav: the theme toggle beside it is its own island and absent without scripts.
        const origin = nav.getBoundingClientRect();
        // Hidden markup (the closed phone menu, closed submenus) is not part of what the visitor sees.
        const shown = (selector: string) =>
            [...nav.querySelectorAll(selector)].filter((el) => el.getClientRects().length > 0);
        const item = (el: Element) => {
            const style = getComputedStyle(el);
            const marker = getComputedStyle(el, '::after');
            return [
                box(el),
                text(el),
                el.getAttribute('aria-current'),
                el.hasAttribute('data-active'),
                el.getAttribute('aria-expanded'),
                style.color,
                style.backgroundColor,
                marker.opacity,
                el.querySelectorAll('svg').length,
            ].join(' | ');
        };
        return {
            nav: box(nav),
            toggle: shown('[data-menu-toggle], .nav-toggle').map(
                (el) => `${box(el)} ${el.getAttribute('aria-label')} ${el.querySelectorAll('svg').length}`,
            ),
            list: shown('#primary-menu').map(box),
            items: shown('#primary-menu > li > a, #primary-menu > li > button').map(item),
            current: shown('[aria-current="page"], [data-active]').map(text),
        };
    });
}

/**
 * The menu as the server sends it and after the island has mounted. "Server" keeps scripts running but blocks every
 * script file, so the markup is what the browser paints before hydration (the `<noscript>` fallback styles do not apply).
 */
async function bothLayouts(browser: Browser, path: string, viewport: { width: number; height: number }) {
    const layouts = [];
    for (const mounted of [false, true]) {
        const context = await browser.newContext({ viewport });
        const page = await context.newPage();
        if (!mounted) await page.route(/\.js(\?.*)?$/, (route) => route.abort());
        await page.goto(path);
        if (mounted) await page.locator('[data-island="site/NavMenu"][data-island-ready]').waitFor();
        await page.evaluate(() => document.fonts.ready);
        // Let the colour/opacity transitions settle.
        await page.waitForTimeout(400);
        layouts.push(await menuLayout(page));
        await context.close();
    }
    return layouts;
}

const pages = [
    { name: 'the home page', path: '/', current: false },
    { name: 'the Downloads page', path: '/downloads', current: true },
    { name: 'a Department page', path: `/departments/${DEMO_DEPARTMENTS.cit.slug}`, current: true },
];

for (const { name, path, current } of pages) {
    test.describe(`site header menu on ${name}`, () => {
        test('desktop: the server menu is laid out exactly as the mounted island', async ({ browser }) => {
            const [server, mounted] = await bothLayouts(browser, path, DESKTOP);
            expect(server.items.length).toBeGreaterThan(2);
            expect(server.toggle).toEqual([]);
            if (current) expect(server.current.length).toBeGreaterThan(0);
            expect(server).toEqual(mounted);
        });

        test('phone: the menu is closed, the toggle is visible, and both agree', async ({ browser }) => {
            const [server, mounted] = await bothLayouts(browser, path, PHONE);
            expect(server.list).toEqual([]);
            expect(server.items).toEqual([]);
            expect(server.toggle).toEqual([expect.stringContaining('Open menu')]);
            expect(server).toEqual(mounted);
        });

        test('without scripts the desktop menu is the same, and on a phone every link stays visible', async ({
            browser,
        }) => {
            const [, mounted] = await bothLayouts(browser, path, DESKTOP);
            const desktop = await browser.newContext({ javaScriptEnabled: false, viewport: DESKTOP });
            const plain = await desktop.newPage();
            await plain.goto(path);
            expect(await menuLayout(plain)).toEqual(mounted);
            await desktop.close();

            const phone = await browser.newContext({ javaScriptEnabled: false, viewport: PHONE });
            const phonePage = await phone.newPage();
            await phonePage.goto(path);
            const layout = await menuLayout(phonePage);
            expect(layout.toggle).toEqual([]);
            expect(layout.items.length).toBe(mounted.items.length);
            await phone.close();
        });
    });
}
