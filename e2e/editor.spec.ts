import fs from 'node:fs';
import path from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

import { AI_LAB_HEADING, AI_LAB_QUOTE, DEMO_POSTS } from './demo-data';
import { postAction } from './actions';
import { ADMIN, CIT } from './auth-state';
import { visitorPage } from './support';

const canvas = (page: Page) => page.frameLocator('iframe[title="Visual canvas"]');
const add = (page: Page, label: string) => page.getByRole('button', { name: `Add ${label}`, exact: true });

/** Waits until the canvas iframe has rendered its document. */
async function editorReady(page: Page) {
    await expect(page.getByRole('toolbar', { name: 'Editor toolbar' })).toBeVisible();
    await expect(canvas(page).locator('#sic-root')).toBeAttached();
}

/** Pointer-drags a palette element onto the lower part of the canvas (drop at the end of the document). */
async function dragFromPalette(page: Page, label: string) {
    const source = await add(page, label).boundingBox();
    const frame = await page.locator('iframe[title="Visual canvas"]').boundingBox();
    expect(source && frame).toBeTruthy();
    await page.mouse.move(source!.x + source!.width / 2, source!.y + source!.height / 2);
    await page.mouse.down();
    await page.mouse.move(source!.x + source!.width / 2 + 24, source!.y + source!.height / 2 + 8, { steps: 4 });
    await page.mouse.move(frame!.x + frame!.width / 2, frame!.y + frame!.height - 24, { steps: 12 });
    await page.mouse.up();
}

test.describe('visual block editor (superadmin)', () => {
    test.use({ storageState: ADMIN });

    test('builds a post from blocks, publishes it and renders it server-side', async ({ page, browser }) => {
        const title = `E2E blocks ${Date.now()}`;
        const headline = `Headline ${Date.now()}`;

        await page.goto('/admin/posts/create');
        await editorReady(page);
        await page.getByLabel('Post title').fill(title);

        // Empty state offers starter templates built from real blocks.
        await expect(page.getByRole('heading', { name: 'Start with a template' })).toBeVisible();
        await expect(page.getByRole('button', { name: /^Two-column cards/ })).toBeVisible();
        await expect(page.getByRole('button', { name: /^FAQ/ })).toBeVisible();

        // Click-to-insert: heading, then a row (inserted after the heading) with a card inside, duplicated.
        await add(page, 'Heading').click();
        await page.getByLabel('Heading text').fill(headline);
        await expect(canvas(page).getByRole('heading', { level: 2, name: headline })).toBeVisible();

        await add(page, 'Row / Flex').click();
        await add(page, 'Card').click();
        await page.getByLabel('Card title').fill('First card');
        await page.getByRole('button', { name: 'Duplicate', exact: true }).click();
        await page.getByLabel('Card title').fill('Second card');
        await expect(canvas(page).locator('[data-block-type="card"]')).toHaveCount(2);

        // Drag & drop from the palette onto the canvas.
        await dragFromPalette(page, 'Table');
        await expect(canvas(page).locator('[data-block-type="table"]')).toHaveCount(1);
        await page.getByLabel('Row 1, column 1').fill('Step');
        await page.getByLabel('Row 1, column 2').fill('Details');
        await page.getByLabel('Row 2, column 1').fill('One');
        await page.getByLabel('Row 2, column 2').fill('Bring your documents');
        await page.getByLabel('Table caption').fill('Enrolment steps');

        // Button via palette (inserted after the selected table).
        await add(page, 'Button').click();
        await page.getByLabel('Button label').fill('Read the guide');
        await page.getByLabel('Button link').fill('/posts');

        // A second block type check: unsafe links are flagged by the inspector.
        await page.getByLabel('Button link').fill('javascript:alert(1)');
        await expect(
            page
                .getByRole('alert')
                .filter({ hasText: /valid link|not allowed|https/i })
                .first(),
        ).toBeVisible();
        await page.getByLabel('Button link').fill('/posts');

        // Canvas selection and keyboard reorder: move the button above the table. (A click on a text of the block that
        // is already selected starts typing, so select the table first.)
        await canvas(page).locator('[data-block-type="table"]').click();
        await expect(page.getByLabel('Table caption')).toHaveValue('Enrolment steps');
        await canvas(page).locator('[data-block-type="button"] a').click();
        await expect(page.getByLabel('Button label')).toHaveValue('Read the guide');
        await page.keyboard.press('Alt+ArrowUp');
        const order = await canvas(page)
            .locator('[data-block-id]')
            .evaluateAll((els) =>
                els
                    .map((el) => el.getAttribute('data-block-type'))
                    .filter((type) => type === 'button' || type === 'table'),
            );
        expect(order).toEqual(['button', 'table']);

        // Undo / redo with the keyboard.
        await page.keyboard.press('Control+z');
        await expect(canvas(page).locator('[data-block-type="button"]')).toHaveCount(1);
        const afterUndo = await canvas(page)
            .locator('[data-block-id]')
            .evaluateAll((els) =>
                els
                    .map((el) => el.getAttribute('data-block-type'))
                    .filter((type) => type === 'button' || type === 'table'),
            );
        expect(afterUndo).toEqual(['table', 'button']);
        await page.keyboard.press('Control+Shift+z');

        // The editor itself has no serious accessibility violations.
        const axe = await new AxeBuilder({ page })
            .include('[data-block-editor]')
            .withTags(['wcag2a', 'wcag2aa'])
            .analyze();
        const serious = axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
        expect(serious.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);

        await page.getByRole('button', { name: 'Publish post' }).click();
        await expect(page).toHaveURL(/\/admin\/posts$/);

        // Excerpt on the public list is plain text, never JSON.
        await page.goto(`/posts?search=${encodeURIComponent(title)}`);
        const link = page.getByRole('link', { name: title }).first();
        await expect(link).toBeVisible();
        const main = await page.locator('main').innerText();
        expect(main).toContain(headline);
        expect(main).not.toMatch(/sic-blocks|"blocks"|\{"type"/);
        const href = await link.getAttribute('href');
        expect(href).toMatch(/^\/posts\//);

        // Public page: rendered on the server (JavaScript disabled), semantic HTML, one h1.
        const visitor = await visitorPage(browser);
        await visitor.goto(href!);
        await expect(visitor.locator('h1')).toHaveCount(1);
        await expect(visitor.getByRole('heading', { level: 2, name: headline })).toBeVisible();
        await expect(visitor.locator('[data-sic-blocks] article')).toHaveCount(2);
        await expect(visitor.getByRole('heading', { level: 3, name: 'First card' })).toBeVisible();
        await expect(visitor.getByRole('heading', { level: 3, name: 'Second card' })).toBeVisible();
        await expect(visitor.locator('[data-sic-blocks] table caption')).toHaveText('Enrolment steps');
        await expect(visitor.locator('[data-sic-blocks] table thead th[scope="col"]')).toHaveText(['Step', 'Details']);
        await expect(visitor.locator('[data-sic-blocks] table tbody td').last()).toHaveText('Bring your documents');
        const button = visitor.getByRole('link', { name: 'Read the guide' });
        await expect(button).toHaveAttribute('href', '/posts');
        // Button was moved above the table, then undo/redo restored the moved order.
        const buttonBeforeTable = await visitor.evaluate(() => {
            const a = document.querySelector('[data-sic-blocks] a[href="/posts"]');
            const t = document.querySelector('[data-sic-blocks] table');
            return Boolean(a && t && a.compareDocumentPosition(t) & Node.DOCUMENT_POSITION_FOLLOWING);
        });
        expect(buttonBeforeTable).toBe(true);
        expect(await visitor.content()).not.toContain('sic-blocks"');
        await visitor.context().close();
    });

    test('builds a post with a 3-image album and opens its lightbox on the public page', async ({ page, browser }) => {
        const title = `E2E album ${Date.now()}`;
        const seal = fs.readFileSync(path.resolve('public/sic-seal.png'));

        await page.goto('/admin/posts/create');
        await editorReady(page);
        await page.getByLabel('Post title').fill(title);
        await add(page, 'Album').click();
        await page
            .getByLabel('Choose image files')
            .setInputFiles([1, 2, 3].map((n) => ({ name: `seal-${n}.png`, mimeType: 'image/png', buffer: seal })));
        await expect(page.getByLabel('Image 3 alt text')).toBeVisible({ timeout: 30_000 });
        for (const n of [1, 2, 3]) await page.getByLabel(`Image ${n} alt text`).fill(`School seal ${n}`);
        await page.getByLabel('Image 2 caption (optional)').fill('Second seal');
        await expect(canvas(page).locator('[data-block-type="album"] img')).toHaveCount(3);

        await page.getByRole('button', { name: 'Publish post' }).click();
        await expect(page).toHaveURL(/\/admin\/posts$/);

        await page.goto(`/posts?search=${encodeURIComponent(title)}`);
        const href = await page.getByRole('link', { name: title }).first().getAttribute('href');
        expect(href).toMatch(/^\/posts\//);

        // Without JavaScript the thumbnails are plain links to the full images.
        const noScript = await visitorPage(browser);
        await noScript.goto(href!);
        const noScriptLinks = noScript.locator('[data-album] a[data-album-item]');
        await expect(noScriptLinks).toHaveCount(3);
        expect(await noScriptLinks.first().getAttribute('href')).toMatch(/^\/storage\/posts\/inline\/[0-9a-f]+\.webp$/);
        await noScript.context().close();

        const context = await browser.newContext();
        const visitor = await context.newPage();
        await visitor.goto(href!);
        const links = visitor.locator('[data-album] a[data-album-item]');
        await expect(links).toHaveCount(3);
        const first = links.first();
        await first.click();

        const lightbox = visitor.getByRole('dialog', { name: 'Image viewer' });
        await expect(lightbox).toBeVisible();
        await expect(lightbox.getByText('1 of 3')).toBeVisible();
        await expect(lightbox.getByRole('img', { name: 'School seal 1' })).toBeVisible();
        // Focus is inside the dialog while it is open.
        expect(await visitor.evaluate(() => document.activeElement?.closest('dialog') !== null)).toBe(true);

        await visitor.keyboard.press('ArrowRight');
        await expect(lightbox.getByText('2 of 3')).toBeVisible();
        await expect(lightbox.getByRole('img', { name: 'School seal 2' })).toBeVisible();
        await expect(lightbox.getByText('Second seal')).toBeVisible();

        await visitor.keyboard.press('Escape');
        await expect(lightbox).toBeHidden();
        await expect(first).toBeFocused();
        await context.close();
    });

    test('keyboard: arrows select, Enter focuses text, Delete removes, palette Enter inserts', async ({ page }) => {
        await page.goto('/admin/posts/create');
        await editorReady(page);

        await add(page, 'Heading').focus();
        await page.keyboard.press('Enter');
        await expect(canvas(page).locator('[data-block-type="heading"]')).toHaveCount(1);
        await add(page, 'Text').focus();
        await page.keyboard.press('Enter');
        await expect(canvas(page).locator('[data-block-type="paragraph"]')).toHaveCount(1);

        await canvas(page).locator('[data-block-type="heading"]').click();
        await page.keyboard.press('ArrowDown');
        await expect(page.getByRole('heading', { name: 'Text properties' })).toBeVisible();
        await page.keyboard.press('Delete');
        await expect(canvas(page).locator('[data-block-type="paragraph"]')).toHaveCount(0);
        await page.keyboard.press('Control+z');
        await expect(canvas(page).locator('[data-block-type="paragraph"]')).toHaveCount(1);
    });

    test('types straight into the canvas: click a text, Tab to the next one, Escape cancels', async ({ page }) => {
        await page.goto('/admin/posts/create');
        await editorReady(page);

        // A new card is selected, so one click on its title starts typing there.
        await add(page, 'Card').click();
        const card = canvas(page).locator('[data-block-type="card"]');
        const title = card.locator('[data-edit="title"]');
        await title.click();
        await expect(title).toHaveAttribute('contenteditable', 'plaintext-only');
        await page.keyboard.press('ControlOrMeta+a');
        await page.keyboard.type('Typed title');

        // Tab saves the title and continues in the card text (rich: Ctrl+Enter saves).
        await page.keyboard.press('Tab');
        const body = card.locator('[data-edit="body"]').first();
        await expect(body).toHaveAttribute('contenteditable', 'true');
        await page.keyboard.press('ControlOrMeta+a');
        await page.keyboard.type('Typed text');
        await page.keyboard.press('ControlOrMeta+Enter');

        await expect(page.getByLabel('Card title')).toHaveValue('Typed title');
        await expect(page.getByRole('textbox', { name: 'Card text', exact: true })).toHaveValue('Typed text');
        await expect(card.locator('[contenteditable]')).toHaveCount(0);

        // Escape throws the typing away.
        await card.locator('[data-edit="title"]').click();
        await page.keyboard.type(' extra');
        await page.keyboard.press('Escape');
        await expect(card.locator('[data-edit="title"]')).toHaveText('Typed title');
        await expect(page.getByLabel('Card title')).toHaveValue('Typed title');

        // Items of lists are typeable too, each written back to its own item.
        await add(page, 'List').click();
        const second = canvas(page).locator('[data-block-type="list"] [data-edit="items.1"]');
        await second.click();
        await page.keyboard.press('ControlOrMeta+a');
        await page.keyboard.type('Changed item');
        await page.keyboard.press('ControlOrMeta+Enter');
        await expect(canvas(page).locator('[data-block-type="list"] li')).toHaveText(['First item', 'Changed item']);
    });

    test('lists: Enter and pasted lines make new items, Backspace at the start joins them', async ({ page }) => {
        await page.goto('/admin/posts/create');
        await editorReady(page);
        await add(page, 'List').click();
        const list = canvas(page).locator('[data-block-type="list"]');
        const item = (index: number) => list.locator(`[data-edit="items.${index}"]`);
        const typing = (index: number) => expect(item(index)).toHaveAttribute('contenteditable', 'true');
        /** A paste of `text` into the element being typed in (the canvas reads `clipboardData`). */
        const paste = (index: number, text: string) =>
            item(index).evaluate((el, value) => {
                const data = new DataTransfer();
                data.setData('text/plain', value);
                el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
            }, text);

        // Enter at the end of an item starts a new, empty item below it.
        await item(0).click();
        await typing(0);
        await page.keyboard.press('End');
        await page.keyboard.press('Enter');
        await typing(1);
        await page.keyboard.type('Inserted');

        // Enter in the middle splits the item; pasted lines become items, without their bullets.
        await page.keyboard.press('ArrowLeft');
        await page.keyboard.press('ArrowLeft');
        await page.keyboard.press('Enter');
        await typing(2);
        await expect(list.locator('li')).toHaveText(['First item', 'Insert', 'ed', 'Second item']);
        await page.keyboard.press('End');
        await page.keyboard.press('Enter');
        await typing(3);
        await paste(3, '- Pasted one\n- Pasted two\n');
        await typing(4);
        await expect(list.locator('li')).toHaveText([
            'First item',
            'Insert',
            'ed',
            'Pasted one',
            'Pasted two',
            'Second item',
        ]);

        // Backspace at the start of an item joins it to the one above.
        await page.keyboard.press('Home');
        await page.keyboard.press('Backspace');
        await typing(3);
        await page.keyboard.press('ControlOrMeta+Enter');
        await expect(list.locator('li')).toHaveText([
            'First item',
            'Insert',
            'ed',
            'Pasted onePasted two',
            'Second item',
        ]);

        // The inspector's item fields work the same way.
        const field = (n: number) => page.getByRole('textbox', { name: `Item ${n}`, exact: true });
        await field(1).click();
        await page.keyboard.press('End');
        await page.keyboard.press('Enter');
        await expect(field(2)).toBeFocused();
        await expect(field(2)).toHaveValue('');
        await field(2).evaluate((el) => {
            const data = new DataTransfer();
            data.setData('text/plain', '1. Alpha\n2. Beta');
            el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
        });
        await expect(field(3)).toBeFocused();
        await expect(list.locator('li')).toHaveText([
            'First item',
            'Alpha',
            'Beta',
            'Insert',
            'ed',
            'Pasted onePasted two',
            'Second item',
        ]);
        await page.keyboard.press('Home');
        await page.keyboard.press('Backspace');
        await expect(field(2)).toBeFocused();
        await expect(field(2)).toHaveValue('AlphaBeta');
    });

    test('the Keyboard shortcuts button and the ? key open the shortcuts dialog', async ({ page }) => {
        await page.goto('/admin/posts/create');
        await editorReady(page);

        await page.getByRole('button', { name: 'Keyboard shortcuts' }).click();
        const dialog = page.getByRole('dialog', { name: 'Keyboard shortcuts' });
        await expect(dialog.getByRole('heading', { name: 'Keyboard shortcuts' })).toBeVisible();
        await expect(dialog.getByText('Duplicate the selected element')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();

        await add(page, 'Heading').focus();
        await page.keyboard.press('Shift+?');
        await expect(dialog).toBeVisible();
    });

    test('opens an existing Markdown post as a legacy block and keeps it rendering unchanged', async ({ page }) => {
        const slug = 'cit-unveils-new-ai-research-laboratory';
        await page.goto(`/posts/${slug}`);
        await expect(page.getByRole('heading', { level: 2, name: AI_LAB_HEADING })).toBeVisible();
        await expect(page.locator('blockquote')).toContainText(AI_LAB_QUOTE);
        await expect(page.locator('h1')).toHaveCount(1);

        await page.goto(`/admin/posts?q=${DEMO_POSTS.aiLab.title}`);
        await page.getByRole('link', { name: `Edit post ${DEMO_POSTS.aiLab.title}` }).click();
        await editorReady(page);
        await expect(page.getByText(/contains.*Markdown \(legacy\).*blocks/)).toBeVisible();
        await canvas(page).locator('[data-block-type="markdown"]').click();
        await expect.poll(() => page.getByLabel('Markdown source').inputValue()).toContain(`# ${AI_LAB_HEADING}`);

        // Converting keeps the content, as editable blocks.
        await page.getByRole('button', { name: 'Convert to blocks' }).first().click();
        await expect(canvas(page).getByRole('heading', { level: 2, name: AI_LAB_HEADING })).toBeVisible();
        await expect(canvas(page).locator('blockquote')).toBeVisible();
        await expect(canvas(page).locator('[data-block-type="markdown"]')).toHaveCount(0);
        // Leave without saving: the seeded post stays Markdown for the other tests.
    });

    test('hero section saved as blocks renders on the home page', async ({ page, browser }) => {
        const headline = `Hero ${Date.now()}`;
        await page.goto('/admin/hero-sections/create');
        await editorReady(page);
        await page.getByLabel('Administrative title').fill(`E2E hero ${headline}`);
        await page.getByRole('button', { name: /Hero intro/ }).click();
        await canvas(page).locator('[data-block-type="heading"]').click();
        await page.getByLabel('Heading text').fill(headline);
        await page.getByRole('button', { name: 'Create section' }).click();
        await expect(page).toHaveURL(/\/admin\/hero-sections$/);

        const visitor = await visitorPage(browser);
        await visitor.goto('/');
        await expect(visitor.getByRole('heading', { level: 2, name: headline })).toBeVisible();
        await expect(visitor.locator('h1')).toHaveCount(1);
        await visitor.context().close();
    });

    test('department hero content is built with the block editor and renders on the Department page', async ({
        page,
        browser,
    }) => {
        const name = `E2E Dept ${Date.now()}`;
        const headline = `Dept hero ${Date.now()}`;
        await page.goto('/admin/departments/create');
        await editorReady(page);
        // The Markdown source editor is gone: the hero content is edited on the visual canvas only.
        await expect(page.getByText('Hero content (Markdown / MDX)')).toHaveCount(0);
        await page.getByLabel('Department name').fill(name);
        await page.getByRole('button', { name: /Hero intro/ }).click();
        await canvas(page).locator('[data-block-type="heading"]').click();
        await page.getByLabel('Heading text').fill(headline);
        await page.getByRole('button', { name: 'Save department' }).click();
        await expect(page).toHaveURL(/\/admin\/departments$/);

        const slug = name.toLowerCase().replaceAll(' ', '-');
        try {
            const visitor = await visitorPage(browser);
            await visitor.goto(`/departments/${slug}`);
            await expect(visitor.getByRole('heading', { level: 2, name: headline })).toBeVisible();
            expect(await visitor.content()).not.toContain('sic-blocks"');
            await visitor.context().close();

            // Reopening shows the saved blocks on the canvas, not a Markdown block.
            await page.getByRole('link', { name: `Edit ${name}` }).click();
            await editorReady(page);
            await expect(canvas(page).getByRole('heading', { name: headline })).toBeVisible();
            await expect(canvas(page).locator('[data-block-type="markdown"]')).toHaveCount(0);
        } finally {
            await page.goto('/admin/departments');
            const href = await page.getByRole('link', { name: `Edit ${name}` }).getAttribute('href');
            const id = Number(href?.match(/departments\/(\d+)\/edit/)?.[1]);
            await postAction(page, 'departments.delete', { json: { id } });
        }
    });

    test('the editor spans the whole admin content area on a wide screen', async ({ page }) => {
        await page.setViewportSize({ width: 1920, height: 1000 });
        for (const route of [
            '/admin/posts/create',
            '/admin/hero-sections/create',
            '/admin/departments/create',
            '/admin/settings/landing',
        ]) {
            await page.goto(route);
            await editorReady(page);
            const { editor, available } = await page
                .getByRole('toolbar', { name: 'Editor toolbar' })
                .evaluate((bar) => {
                    const main = document.querySelector('main')!;
                    const container = bar.closest('.p-6')!;
                    const style = getComputedStyle(container);
                    return {
                        editor: bar.parentElement!.getBoundingClientRect().width,
                        available:
                            main.getBoundingClientRect().width -
                            parseFloat(style.paddingLeft) -
                            parseFloat(style.paddingRight),
                    };
                });
            expect(Math.abs(editor - available), `${route}: editor ${editor}px of ${available}px`).toBeLessThan(2);
        }
    });

    test('landing settings save a block document that the home page renders', async ({ page, browser }) => {
        const text = `Landing note ${Date.now()}`;
        await page.goto('/admin/settings/landing');
        await editorReady(page);
        // Idempotent: the first run converts the seeded Markdown, later runs already have blocks.
        const convert = page.getByRole('button', { name: 'Convert to blocks' }).first();
        if (await convert.isVisible()) await convert.click();
        await add(page, 'Text').click();
        await page.getByLabel('Text', { exact: true }).fill(text);
        await page.getByRole('button', { name: 'Save settings' }).click();
        await expect(page.getByText('Landing page settings updated successfully.')).toBeVisible();

        const visitor = await visitorPage(browser);
        await visitor.goto('/');
        await expect(visitor.getByText(text)).toBeVisible();
        await expect(
            visitor.getByRole('heading', { level: 2, name: 'Welcome to San Isidro College' }).first(),
        ).toBeVisible();
        expect(await visitor.content()).not.toContain('sic-blocks"');
        await visitor.context().close();
    });

    test('the canvas previews content at the width of the public page', async ({ page, browser }) => {
        const frameOf = (el: Element) => {
            const style = getComputedStyle(el);
            return { maxWidth: style.maxWidth, gutter: style.paddingLeft };
        };
        const visitor = await visitorPage(browser);
        await visitor.goto('/posts');
        await visitor.locator('main a[href^="/posts/"]').first().click();
        const postBody = await visitor.locator('main article > div').first().evaluate(frameOf);
        await visitor.goto('/');
        const section = await visitor.locator('main section[data-content-section] > div').first().evaluate(frameOf);
        await visitor.context().close();

        await page.goto('/admin/posts/create');
        await editorReady(page);
        expect(await canvas(page).locator('#sic-root').evaluate(frameOf)).toEqual(postBody);
        await page.goto('/admin/hero-sections/create');
        await editorReady(page);
        expect(await canvas(page).locator('#sic-root').evaluate(frameOf)).toEqual(section);
    });

    test('rejects an invalid block document on the server', async ({ page }) => {
        await page.goto('/admin/posts/create');
        await editorReady(page);
        const result = await postAction(page, 'posts.create', {
            multipart: {
                title: 'Bad doc',
                type: 'article',
                isFeatured: 'false',
                content: JSON.stringify({
                    type: 'sic-blocks',
                    version: 1,
                    blocks: [{ id: 'b1', type: 'image', props: { src: '/storage/x.png', alt: '' } }],
                }),
            },
        });
        expect(result.status).toBe(400);
        expect(result.text).toMatch(/alt text/i);
    });
});

test.describe('visual block editor (department user)', () => {
    test.use({ storageState: CIT });

    test('creates a post for their department with the block editor', async ({ page }) => {
        const title = `CIT blocks ${Date.now()}`;
        await page.goto('/admin/posts/create');
        await editorReady(page);
        await page.getByLabel('Post title').fill(title);
        await add(page, 'Text').click();
        await page.getByLabel('Text', { exact: true }).fill('Written by a department editor.');
        await page.getByRole('button', { name: 'Publish post' }).click();
        await expect(page).toHaveURL(/\/admin\/posts$/);
        await expect(page.locator('main')).toContainText(title);
    });
});

test.describe('unsaved changes guard (superadmin)', () => {
    test.use({ storageState: ADMIN });

    test('editing a post, then clicking a sidebar link, asks before leaving', async ({ page }) => {
        await page.goto('/admin/posts/create');
        await editorReady(page);

        // Untouched form: leaving is free.
        await page.getByRole('link', { name: 'Departments', exact: true }).first().click();
        await expect(page).toHaveURL(/\/admin\/departments/);

        await page.goto('/admin/posts/create');
        await editorReady(page);
        await page.getByLabel('Post title').fill('Unsaved draft');
        await page.getByRole('link', { name: 'Departments', exact: true }).first().click();

        const dialog = page.getByRole('dialog', { name: 'Discard unsaved changes?' });
        await expect(dialog).toBeVisible();
        await expect(page).toHaveURL(/\/admin\/posts\/create/);
        await dialog.getByRole('button', { name: 'Keep editing' }).click();
        await expect(dialog).toBeHidden();
        await expect(page.getByLabel('Post title')).toHaveValue('Unsaved draft');

        await page.getByRole('link', { name: 'Departments', exact: true }).first().click();
        await page.getByRole('dialog').getByRole('button', { name: 'Discard' }).click();
        await expect(page).toHaveURL(/\/admin\/departments/);
    });

    /** Opens the first Post's edit page from the list (a client-side navigation) and changes its title. */
    async function editFirstPost(page: Page) {
        await page.goto('/admin/posts');
        await page.locator('a[href^="/admin/posts/"][href$="/edit"]').first().click();
        await expect(page).toHaveURL(/\/admin\/posts\/\d+\/edit$/);
        await editorReady(page);
        await page.getByLabel('Post title').fill('Unsaved edit');
        return page.url();
    }

    test('Back from a changed post asks once; Keep editing leaves the URL on the edit page', async ({ page }) => {
        const editUrl = await editFirstPost(page);
        const asked: string[] = [];
        page.on('dialog', (dialog) => {
            asked.push(dialog.type());
            void dialog.dismiss();
        });
        await page.evaluate(() => history.back());
        await expect.poll(() => asked).toEqual(['beforeunload']);
        // The browser went back before it asked; the guard puts the history entry back.
        await expect.poll(() => page.url()).toBe(editUrl);
        await expect(page.getByLabel('Post title')).toHaveValue('Unsaved edit');

        // Still guarded afterwards: Back asks again, and leaving goes to the list.
        page.removeAllListeners('dialog');
        page.once('dialog', (dialog) => void dialog.accept());
        await page.evaluate(() => history.back());
        await expect(page).toHaveURL(/\/admin\/posts$/);
    });

    test('Discard on a link to a public page does not make the browser ask a second time', async ({ page }) => {
        await editFirstPost(page);
        // The public site is a full page load, which is where `beforeunload` would ask again.
        let asked = 0;
        page.on('dialog', (dialog) => {
            asked++;
            void dialog.dismiss();
        });
        await page.getByRole('link', { name: 'View site' }).first().click();
        await page.getByRole('dialog').getByRole('button', { name: 'Discard' }).click();
        await expect(page).toHaveURL(/\/$/);
        expect(asked).toBe(0);
    });
});
