import fs from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';

import { apiPost, apiPut } from './actions';
import { DEMO_DEPARTMENTS } from './demo-data';
import { ADMIN } from './auth-state';
import { UPLOADS } from './env';
import { SEAL_BYTES, cacheDirectives } from './support';

const PUBLIC_PAGES = ['/', '/posts', '/downloads', '/contact', `/departments/${DEMO_DEPARTMENTS.cit.slug}`];
const ADMIN_PAGES = ['/dashboard', '/admin/posts', '/admin/posts/create', '/admin/carousels', '/admin/departments'];

/** Collects every CSP violation the document reports (console message or `securitypolicyviolation` event). */
async function watchViolations(page: Page): Promise<() => Promise<string[]>> {
    const messages: string[] = [];
    page.on('console', (message) => {
        if (/content security policy/i.test(message.text())) messages.push(message.text());
    });
    await page.addInitScript(() => {
        Reflect.set(window, '__violations', []);
        document.addEventListener('securitypolicyviolation', (event) => {
            const list = Reflect.get(window, '__violations') as string[];
            list.push(`${event.violatedDirective} ${event.blockedURI} ${event.sample}`.trim());
        });
    });
    return async () => [
        ...messages,
        ...(await page.evaluate(() => (Reflect.get(window, '__violations') as string[] | undefined) ?? [])),
    ];
}

const scriptSrcOf = (policy: string | undefined) =>
    policy
        ?.split(';')
        .map((directive) => directive.trim())
        .find((directive) => directive.startsWith('script-src '));

const nonceOf = (policy: string | undefined) => scriptSrcOf(policy)?.match(/'nonce-([^']+)'/)?.[1];

/** Every island of the page has mounted (`data-island-ready`). */
const expectIslandsMounted = (page: Page, path: string) =>
    expect(page.locator('[data-island]:not([data-island-ready])'), path).toHaveCount(0, { timeout: 15_000 });

/** The shape `App\Http\Middleware\RequestPolicy` gives an HTML page: base directives, nonce scripts, no inline scripts. */
function expectHtmlPolicy(policy: string | undefined, body: string, path: string) {
    const scriptSrc = scriptSrcOf(policy);
    expect(scriptSrc, path).toMatch(/^script-src 'self' (?:\S+ )?'nonce-[^']+'/);
    expect(scriptSrc, path).not.toContain('unsafe-inline');
    expect(scriptSrc, path).not.toContain('unsafe-eval');
    for (const directive of ["frame-ancestors 'none'", "object-src 'none'", "base-uri 'self'", "form-action 'self'"]) {
        expect(policy, path).toContain(directive);
    }
    // The nonce of the header is the one on the page's own scripts.
    const nonce = nonceOf(policy)!;
    expect(body, path).toContain(`nonce="${nonce}"`);
}

test.describe('Content-Security-Policy', () => {
    test('public pages allow only same-origin and nonce scripts, and still render and hydrate', async ({ page }) => {
        const violations = await watchViolations(page);
        for (const path of PUBLIC_PAGES) {
            const response = await page.goto(path);
            expectHtmlPolicy(response?.headers()['content-security-policy'], await response!.text(), path);
            await expect(page.locator('h1').first(), path).toBeVisible();
            // Every island has mounted.
            await expectIslandsMounted(page, path);
            expect(await violations(), path).toEqual([]);
        }
    });

    test('responses other than HTML carry the base directives', async ({ request }) => {
        for (const path of ['/sitemap.xml', '/rss.xml', '/robots.txt', '/csrf-token']) {
            const policy = (await request.get(path)).headers()['content-security-policy'];
            for (const directive of [
                "frame-ancestors 'none'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]) {
                expect(policy, path).toContain(directive);
            }
        }
    });

    test('security headers are set on pages', async ({ request }) => {
        for (const path of ['/', '/login']) {
            const headers = (await request.get(path)).headers();
            expect(headers['x-frame-options'], path).toBe('DENY');
            expect(headers['x-content-type-options'], path).toBe('nosniff');
            expect(headers['referrer-policy'], path).toBe('strict-origin-when-cross-origin');
        }
    });

    test('the theme bootstrap runs under the policy', async ({ page }) => {
        await page.emulateMedia({ colorScheme: 'dark' });
        const violations = await watchViolations(page);
        await page.goto('/');
        await expect(page.locator('html')).toHaveClass(/dark/);
        expect(await violations()).toEqual([]);
    });

    test.describe('admin', () => {
        test.use({ storageState: ADMIN });

        test('admin pages mount their islands and navigation runs no blocked script', async ({ page }) => {
            const violations = await watchViolations(page);
            await page.goto(ADMIN_PAGES[0]);
            for (const path of ADMIN_PAGES.slice(1)) {
                const response = await page.goto(path);
                expectHtmlPolicy(response?.headers()['content-security-policy'], await response!.text(), path);
                await expect(page.locator('h1').first(), path).toBeVisible();
                await expectIslandsMounted(page, path);
            }
            // Every sidebar link is a full page load with its own policy.
            await page.goto('/dashboard');
            const sidebar = page.locator('[data-sidebar="content"]');
            await sidebar.getByRole('link', { name: 'Posts', exact: true }).click();
            await expect(page).toHaveURL(/\/admin\/posts$/);
            await sidebar.getByRole('link', { name: 'Departments', exact: true }).click();
            await expect(page).toHaveURL(/\/admin\/departments$/);
            await expect(page.getByRole('heading', { level: 1, name: 'Departments' })).toBeVisible();
            expect(await violations()).toEqual([]);
        });
    });
});

test.describe('anonymous actions', () => {
    test('a call without a CSRF token is refused before anything runs', async ({ request }) => {
        const response = await request.post('/_actions/contact.send', { multipart: { name: 'x' } });
        expect(response.status()).toBe(419);
    });

    test('a signed-out call to a private action is answered 401 in the action error shape', async ({ request }) => {
        for (const name of ['posts.create', 'users.create', 'account.deleteAccount', 'downloads.create']) {
            const response = await apiPost(request, `/_actions/${name}`, { multipart: { title: 'x' } });
            expect(response.status(), name).toBe(401);
            expect((await response.json()).error, name).toMatchObject({ type: 'ActionError', code: 'UNAUTHORIZED' });
            expect(cacheDirectives(response.headers()['cache-control']), name).toEqual(['no-store']);
        }
    });

    test('the public contact action is still reachable (and validates its input)', async ({ request }) => {
        const response = await apiPost(request, '/_actions/contact.send', { multipart: { title: 'x' } });
        expect(response.status()).toBe(400);
        expect((await response.json()).error).toMatchObject({ type: 'ActionInputError' });
    });
});

test.describe('API paths the app does not serve', () => {
    for (const path of ['/api/auth/change-password', '/api/auth/update-user', '/api/auth/delete-user']) {
        test(`${path} is not served`, async ({ request }) => {
            const response = await apiPost(request, path, { data: {} });
            expect(response.status()).toBe(404);
        });
    }
});

test.describe('HTTP caching', () => {
    test('public pages are cacheable, tagged and answered with 304', async ({ request }) => {
        const first = await request.get('/posts');
        expect(cacheDirectives(first.headers()['cache-control'])).toEqual([
            'max-age=60',
            'public',
            's-maxage=60',
            'stale-while-revalidate=600',
        ]);
        const etag = first.headers()['etag'];
        expect(etag).toMatch(/^W\/"/);

        const again = await request.get('/posts', { headers: { 'if-none-match': etag } });
        expect(again.status()).toBe(304);
        expect(again.headers()['etag']).toBe(etag);
        expect(again.headers()['cache-control']).toContain('s-maxage=60');
    });

    test('anonymous public pages set no cookie and carry no CSRF token', async ({ request }) => {
        for (const path of ['/', '/posts', '/downloads']) {
            const response = await request.get(path);
            expect(response.headers()['set-cookie'], path).toBeUndefined();
            expect(await response.text(), path).not.toContain('name="csrf-token"');
        }
    });

    test('signed-in pages are never tagged or cached', async ({ browser }) => {
        const context = await browser.newContext({ storageState: ADMIN });
        const response = await context.request.get('/admin/posts');
        expect(cacheDirectives(response.headers()['cache-control'])).toEqual(['no-store']);
        expect(response.headers()['etag']).toBeUndefined();
        await context.close();
    });

    test('built assets are served from /build/', async ({ request, page }) => {
        await page.goto('/');
        const stylesheet = await page.locator('link[rel="stylesheet"]').first().getAttribute('href');
        expect(new URL(stylesheet!, page.url()).pathname).toMatch(/^\/build\//);
        expect((await request.get(stylesheet!)).status()).toBe(200);
    });

    test.describe('stored files', () => {
        test.use({ storageState: ADMIN });

        test('answer If-None-Match and If-Modified-Since with 304 and are cached as immutable', async ({ request }) => {
            const uploaded = await apiPost(request, '/api/admin/uploads/image', {
                multipart: { kind: 'content', image: { name: 'seal.png', mimeType: 'image/png', buffer: SEAL_BYTES } },
            });
            expect(uploaded.ok()).toBe(true);
            const { url } = (await uploaded.json()) as { url: string };

            const file = await request.get(url);
            expect(file.status()).toBe(200);
            expect(cacheDirectives(file.headers()['cache-control'])).toEqual([
                'immutable',
                'max-age=31536000',
                'public',
            ]);
            const { etag, 'last-modified': modified } = file.headers();
            expect(etag).toMatch(/^W\/"/);
            expect(file.headers()['content-encoding']).toBeUndefined();

            const byEtag = await request.get(url, { headers: { 'if-none-match': etag } });
            expect(byEtag.status()).toBe(304);
            expect(await byEtag.body()).toHaveLength(0);
            const byDate = await request.get(url, { headers: { 'if-modified-since': modified } });
            expect(byDate.status()).toBe(304);
            const stale = await request.get(url, { headers: { 'if-none-match': 'W/"other"' } });
            expect(stale.status()).toBe(200);
        });
    });
});

test.describe('uploaded images', () => {
    test.use({ storageState: ADMIN });

    test('an SVG sent as a PNG is rejected after sign-in and stores nothing', async ({ request }) => {
        const svg = Buffer.from(
            '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40"/></svg>',
        );
        const response = await apiPost(request, '/api/admin/uploads/image', {
            multipart: { kind: 'content', image: { name: 'logo.png', mimeType: 'image/png', buffer: svg } },
        });
        expect(response.status()).toBe(400);
        expect(await response.json()).toMatchObject({ message: expect.stringContaining('could not be read') });
    });

    test('a signed-out upload is refused, and a large signed-out body is cut off before it is read', async ({
        playwright,
        baseURL,
    }) => {
        const anonymous = await playwright.request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
        const upload = (buffer: Buffer) =>
            apiPost(anonymous, '/api/admin/uploads/image', {
                multipart: { kind: 'content', image: { name: 'seal.png', mimeType: 'image/png', buffer } },
            });
        expect((await upload(SEAL_BYTES.subarray(0, 100))).status()).toBe(401);
        expect((await upload(Buffer.alloc(100 * 1024))).status()).toBe(413);
        await anonymous.dispose();
    });
});

test.describe('request body limit', () => {
    test.use({ storageState: ADMIN });

    test('still accepts the largest upload: a 100 MB video PUT', async ({ request }) => {
        const size = 100 * 1024 * 1024;
        const grant = await apiPost(request, '/api/admin/uploads/video', {
            data: { fileName: 'big.mp4', contentType: 'video/mp4', size },
        });
        expect(grant.ok(), await grant.text()).toBe(true);
        const { key, url, contentType } = (await grant.json()) as { key: string; url: string; contentType: string };

        const put = await apiPut(request, url, {
            headers: { 'Content-Type': contentType },
            data: Buffer.alloc(size, 7),
        });
        expect(put.status(), await put.text()).toBe(204);
        const file = path.resolve(UPLOADS, key);
        expect(fs.statSync(file).size).toBe(size);
        fs.rmSync(file);
    });
});
