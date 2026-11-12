import { describe, expect, it, vi } from 'vitest';
import { createUnsavedGuard, isPlainPrimaryClick, type Held } from './unsaved-guard';

const click = (over: Partial<MouseEvent> = {}) =>
    ({
        button: 0,
        metaKey: false,
        ctrlKey: false,
        shiftKey: false,
        altKey: false,
        target: {},
        preventDefault: vi.fn(),
        stopPropagation: vi.fn(),
        ...over,
    }) as unknown as MouseEvent;

/** Whether the fake event's `preventDefault` spy was called. */
const wasPrevented = (event: object): boolean =>
    (Reflect.get(event, 'preventDefault') as { mock: { calls: unknown[] } }).mock.calls.length > 0;

const form = (own = false) =>
    ({ closest: () => (own ? {} : null), requestSubmit: vi.fn() }) as unknown as HTMLFormElement;
const submit = (target: HTMLFormElement) =>
    ({ target, submitter: null, preventDefault: vi.fn(), stopPropagation: vi.fn() }) as unknown as SubmitEvent;

function setup(dirty: boolean, link: URL | null = new URL('https://sic.test/admin/posts')) {
    const state = { dirty };
    const held: Held[] = [];
    const navigate = vi.fn(async () => {});
    const guard = createUnsavedGuard({
        isDirty: () => state.dirty,
        onHold: (h) => held.push(h),
        navigate,
        linkAt: () => link,
    });
    return { state, held, navigate, guard };
}

describe('isPlainPrimaryClick', () => {
    it('is the unmodified left button only', () => {
        expect(isPlainPrimaryClick(click())).toBe(true);
        expect(isPlainPrimaryClick(click({ button: 1 }))).toBe(false);
        for (const key of ['metaKey', 'ctrlKey', 'shiftKey', 'altKey'] as const)
            expect(isPlainPrimaryClick(click({ [key]: true }))).toBe(false);
    });
});

describe('unsaved guard', () => {
    it('holds a plain link click while dirty and lets it through when clean', () => {
        const dirty = setup(true);
        const event = click();
        dirty.guard.click(event);
        expect(wasPrevented(event)).toBe(true);
        expect(dirty.held).toEqual([{ href: 'https://sic.test/admin/posts' }]);

        const clean = setup(false);
        const free = click();
        clean.guard.click(free);
        expect(wasPrevented(free)).toBe(false);
        expect(clean.held).toEqual([]);
    });

    it('ignores modified clicks and targets that are not plain links', () => {
        const { guard, held } = setup(true);
        guard.click(click({ ctrlKey: true }));
        expect(held).toEqual([]);
        const other = setup(true, null);
        other.guard.click(click());
        expect(other.held).toEqual([]);
    });

    it('holds other forms but never the guarded page’s own', () => {
        const { guard, held } = setup(true);
        const other = form();
        const event = submit(other);
        guard.submit(event);
        expect(wasPrevented(event)).toBe(true);
        expect(held).toEqual([{ form: other, submitter: null }]);

        const own = submit(form(true));
        guard.submit(own);
        expect(wasPrevented(own)).toBe(false);
        expect(held).toHaveLength(1);
    });

    it('asks before unload only while dirty', () => {
        const { guard, state } = setup(true);
        const unload = { preventDefault: vi.fn() } as unknown as BeforeUnloadEvent;
        guard.beforeUnload(unload);
        expect(wasPrevented(unload)).toBe(true);

        state.dirty = false;
        const quiet = { preventDefault: vi.fn() } as unknown as BeforeUnloadEvent;
        guard.beforeUnload(quiet);
        expect(wasPrevented(quiet)).toBe(false);
    });

    it('proceed follows the held link without asking the browser again', async () => {
        const { guard, navigate } = setup(true);
        const during = { preventDefault: vi.fn() } as unknown as BeforeUnloadEvent;
        navigate.mockImplementation(async () => guard.beforeUnload(during));
        await guard.proceed({ href: 'https://sic.test/admin/posts' });
        expect(navigate).toHaveBeenCalledWith('https://sic.test/admin/posts');
        expect(wasPrevented(during)).toBe(false);
    });

    it('proceed re-submits a held form and keeps the guard open until the page is left or shown again', async () => {
        const { guard } = setup(true);
        // A valid form: requestSubmit fires its submit event, which the guard lets through.
        const requestSubmit = vi.fn((_submitter: HTMLElement | null) => {
            const event = submit(target);
            guard.submit(event);
            expect(wasPrevented(event)).toBe(false);
        });
        const target = { closest: () => null, requestSubmit } as unknown as HTMLFormElement;
        await guard.proceed({ form: target, submitter: null });
        expect(requestSubmit).toHaveBeenCalledWith(null);
        const during = { preventDefault: vi.fn() } as unknown as BeforeUnloadEvent;
        guard.beforeUnload(during);
        expect(wasPrevented(during)).toBe(false);

        guard.settled();
        const after = { preventDefault: vi.fn() } as unknown as BeforeUnloadEvent;
        guard.beforeUnload(after);
        expect(wasPrevented(after)).toBe(true);
    });

    it('a held form the browser refuses to submit leaves the page guarded', async () => {
        const { guard } = setup(true);
        // An invalid form: requestSubmit fires no submit event.
        await guard.proceed({ form: form(), submitter: null });
        const unload = { preventDefault: vi.fn() } as unknown as BeforeUnloadEvent;
        guard.beforeUnload(unload);
        expect(wasPrevented(unload)).toBe(true);
    });
});
