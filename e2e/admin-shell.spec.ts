import { expect, test, type Browser, type Page } from '@playwright/test';
import { ADMIN, CIT } from './auth-state';

/** Layout of the shell as seen by the visitor: visible sidebar, buttons and links, toggle, header and main, rounded to px. */
async function shellLayout(page: Page) {
    return page.evaluate(() => {
        const box = (el: Element) => {
            const r = el.getBoundingClientRect();
            return [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)].join(',');
        };
        const shell = document.querySelector('[data-island="admin/AdminShell"]')!;
        // Hidden markup (the desktop sidebar on a phone) is not part of what the visitor sees.
        const shown = (selector: string) =>
            [...shell.querySelectorAll(selector)].filter((el) => el.getClientRects().length > 0);
        return {
            sidebar: shown('[data-sidebar="sidebar"]').map(box),
            buttons: shown('[data-sidebar="menu-button"]').map(
                (el) =>
                    `${box(el)} ${el.getAttribute('data-active')} ${(el.textContent ?? '').replace(/\s+/g, ' ').trim()}`,
            ),
            current: shown('[data-sidebar="menu-button"][aria-current="page"]').map((el) => el.getAttribute('href')),
            toggle: shown('[data-sidebar="trigger"] svg').map(
                (el) => `${box(el)} ${el.getAttribute('class')?.match(/lucide-panel-left-\w+/)?.[0]}`,
            ),
            header: shown('header, nav[aria-label="breadcrumb"]').map(
                (el) => `${box(el)} ${(el.textContent ?? '').replace(/\s+/g, '')}`,
            ),
            main: box(shell.querySelector('main')!),
        };
    });
}

/** The page as the server sends it (no script runs) and after the shell island has mounted. */
async function bothLayouts(
    browser: Browser,
    storageState: string,
    path: string,
    cookies: { name: string; value: string }[] = [],
    viewport = { width: 1280, height: 800 },
) {
    const layouts = [];
    for (const scripts of [false, true]) {
        const context = await browser.newContext({
            storageState,
            javaScriptEnabled: scripts,
            viewport,
        });
        if (cookies.length)
            await context.addCookies(cookies.map((c) => ({ ...c, url: test.info().project.use.baseURL! })));
        const page = await context.newPage();
        await page.goto(path);
        if (scripts) await page.locator('[data-island="admin/AdminShell"][data-island-ready]').waitFor();
        await page.evaluate(() => document.fonts.ready);
        layouts.push(await shellLayout(page));
        await context.close();
    }
    return layouts;
}

test.describe('admin shell before its script runs', () => {
    test('a Superadmin page paints the sidebar exactly as the mounted shell does', async ({ browser }) => {
        const [server, mounted] = await bothLayouts(browser, ADMIN, '/admin/posts');
        expect(server.current).toEqual(['/admin/posts']);
        expect(server.buttons.length).toBeGreaterThan(10);
        expect(server).toEqual(mounted);
    });

    test('a collapsed sidebar stays collapsed', async ({ browser }) => {
        const [server, mounted] = await bothLayouts(browser, ADMIN, '/admin/downloads', [
            { name: 'sidebar_state', value: 'false' },
        ]);
        expect(server.sidebar[0]).toMatch(/^\d+,\d+,(4\d|5\d),/);
        expect(server).toEqual(mounted);
    });

    test('a Department user sees their fixed Department and the Org chart link', async ({ browser }) => {
        const [server, mounted] = await bothLayouts(browser, CIT, '/admin/posts');
        expect(server.buttons.some((b) => b.includes('Org chart'))).toBe(true);
        expect(server).toEqual(mounted);
    });

    test('on a phone neither shows the desktop sidebar, and the toggle opens the drawer', async ({ browser }) => {
        const [server, mounted] = await bothLayouts(browser, ADMIN, '/admin/posts', [], { width: 390, height: 844 });
        expect(server.sidebar).toEqual([]);
        expect(server.toggle).toEqual([expect.stringContaining('lucide-panel-left-open')]);
        expect(server).toEqual(mounted);
    });
});
