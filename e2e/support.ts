import fs from 'node:fs';
import path from 'node:path';
import { expect, type Browser, type Locator, type Page } from '@playwright/test';

/** Unique per run so retries and other specs never collide with the rows a spec creates. */
export const RUN = Date.now().toString(36);

/** A small real image every uploader accepts. */
export const SEAL = path.resolve('public/sic-seal.png');
export const SEAL_BYTES = fs.readFileSync(SEAL);

/** A signed-out visitor's page with JavaScript disabled: what the server-rendered HTML alone shows. Close it with `page.context().close()`. */
export async function visitorPage(browser: Browser): Promise<Page> {
    const context = await browser.newContext({ javaScriptEnabled: false });
    return context.newPage();
}

/** Resolves once the island of `component` (a path like `admin/posts/PostForm`) has mounted (`data-island-ready`). */
export const islandHydrated = (page: Page, component: string) =>
    page.waitForFunction((name) => !!document.querySelector(`[data-island$="${name}"][data-island-ready]`), component);

/**
 * The directives of a Cache-Control header, sorted: PHP orders them alphabetically and adds `private` to a bare
 * `no-store`, so specs compare the set of directives, not the header string.
 */
export const cacheDirectives = (header: string | undefined) =>
    (header ?? '')
        .split(',')
        .map((directive) => directive.trim())
        .filter((directive) => directive && directive !== 'private')
        .sort();

/** Clicks `trigger` until `expected` shows: the click is ignored while its island is still mounting. */
export const clickUntilVisible = (trigger: Locator, expected: Locator) =>
    expect(async () => {
        await trigger.click({ timeout: 2000 });
        await expect(expected).toBeVisible({ timeout: 2000 });
    }).toPass({ timeout: 15_000 });
