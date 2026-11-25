import { describe, expect, it } from 'vitest';
import { inlineEditPatch, itemOf, itemsEditPatch, parseEditPath, pastedItems } from './inline-edit';

describe('itemOf', () => {
    it('reads an entry of a top-level list', () => {
        expect(itemOf('items.3')).toEqual({ path: 'items', index: 3 });
        expect(itemOf('items')).toBeNull();
        expect(itemOf('items.1.title')).toBeNull();
    });
});

describe('itemsEditPatch', () => {
    const props = { ordered: false, items: ['One', 'Two', 'Three'] };

    it('splits an item in two (Enter)', () => {
        expect(itemsEditPatch(props, { path: 'items', start: 1, remove: 1, insert: ['Tw', 'o'] }, 50)).toEqual({
            items: ['One', 'Tw', 'o', 'Three'],
        });
    });

    it('joins an item into the one before (Backspace at the start)', () => {
        expect(itemsEditPatch(props, { path: 'items', start: 0, remove: 2, insert: ['OneTwo'] }, 50)).toEqual({
            items: ['OneTwo', 'Three'],
        });
    });

    it('refuses to grow past the maximum, a range outside the list, and non-lists', () => {
        expect(itemsEditPatch(props, { path: 'items', start: 0, remove: 1, insert: ['a', 'b'] }, 3)).toBeNull();
        expect(itemsEditPatch(props, { path: 'items', start: 2, remove: 2, insert: [] }, 50)).toBeNull();
        expect(itemsEditPatch(props, { path: 'ordered', start: 0, remove: 0, insert: ['x'] }, 50)).toBeNull();
        expect(itemsEditPatch(props, { path: 'missing', start: 0, remove: 0, insert: ['x'] }, 50)).toBeNull();
    });
});

describe('pastedItems', () => {
    it('makes one entry per non-blank line', () => {
        expect(pastedItems('One\r\nTwo\n\n  Three  \r')).toEqual(['One', 'Two', 'Three']);
    });

    it('drops leading bullets and numbers, not hyphens inside the text', () => {
        expect(pastedItems('- One\n• Two\n* Three\n1. Four\n12) Five\nSix - seven\n-Eight')).toEqual([
            'One',
            'Two',
            'Three',
            'Four',
            'Five',
            'Six - seven',
            '-Eight',
        ]);
    });
});

describe('parseEditPath', () => {
    it('turns numeric segments into indexes', () => {
        expect(parseEditPath('title')).toEqual(['title']);
        expect(parseEditPath('items.2.title')).toEqual(['items', 2, 'title']);
        expect(parseEditPath('rows.0.1')).toEqual(['rows', 0, 1]);
    });
});

describe('inlineEditPatch', () => {
    it('replaces a top-level text prop', () => {
        expect(inlineEditPatch({ text: 'Old', level: 2 }, { path: 'text', part: null, value: 'New' })).toEqual({
            text: 'New',
        });
    });

    it('returns null when nothing changed', () => {
        expect(inlineEditPatch({ text: 'Same' }, { path: 'text', part: null, value: 'Same' })).toBeNull();
    });

    it('writes nested items without touching the others', () => {
        const props = {
            items: [
                { title: 'A', body: 'x' },
                { title: 'B', body: 'y' },
            ],
        };
        const patch = inlineEditPatch(props, { path: 'items.1.title', part: null, value: 'B2' });
        expect(patch).toEqual({
            items: [
                { title: 'A', body: 'x' },
                { title: 'B2', body: 'y' },
            ],
        });
        expect(props.items[1].title).toBe('B');
    });

    it('writes table cells and list items by index', () => {
        expect(
            inlineEditPatch(
                {
                    rows: [
                        ['a', 'b'],
                        ['c', 'd'],
                    ],
                },
                { path: 'rows.1.0', part: null, value: 'C' },
            ),
        ).toEqual({
            rows: [
                ['a', 'b'],
                ['C', 'd'],
            ],
        });
        expect(inlineEditPatch({ items: ['x', ' ', 'z'] }, { path: 'items.2', part: null, value: 'Z' })).toEqual({
            items: ['x', ' ', 'Z'],
        });
    });

    it('replaces one paragraph of a multi-paragraph prop', () => {
        const props = { body: 'One\n\nTwo\n\nThree' };
        expect(inlineEditPatch(props, { path: 'body', part: 1, value: 'Deux' })).toEqual({
            body: 'One\n\nDeux\n\nThree',
        });
    });

    it('drops an emptied paragraph and splits on a typed blank line', () => {
        expect(inlineEditPatch({ body: 'One\n\nTwo' }, { path: 'body', part: 0, value: '  ' })).toEqual({
            body: 'Two',
        });
        expect(inlineEditPatch({ body: 'One\n\nTwo' }, { path: 'body', part: 1, value: 'Two\n\nMore' })).toEqual({
            body: 'One\n\nTwo\n\nMore',
        });
    });

    it('ignores stale or invalid paths', () => {
        expect(inlineEditPatch({ title: 'T' }, { path: 'missing', part: null, value: 'x' })).toBeNull();
        expect(inlineEditPatch({ items: ['a'] }, { path: 'items.5', part: null, value: 'x' })).toBeNull();
        expect(inlineEditPatch({ level: 2 }, { path: 'level', part: null, value: 'x' })).toBeNull();
        expect(inlineEditPatch({ body: 'One' }, { path: 'body', part: 3, value: 'x' })).toBeNull();
    });
});
