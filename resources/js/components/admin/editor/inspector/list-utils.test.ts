import { describe, expect, it } from 'vitest';
import { moveItem, removeItem, spliceItems } from './list-utils';

describe('spliceItems', () => {
    it('splits one item into two and joins two into one', () => {
        expect(spliceItems(['ab', 'c'], { start: 0, remove: 1, insert: ['a', 'b'] }, 10)).toEqual(['a', 'b', 'c']);
        expect(spliceItems(['a', 'b', 'c'], { start: 0, remove: 2, insert: ['ab'] }, 10)).toEqual(['ab', 'c']);
    });

    it('refuses to grow past the maximum, but may stay at it', () => {
        expect(spliceItems(['a', 'b'], { start: 1, remove: 1, insert: ['b', 'c'] }, 2)).toBeNull();
        expect(spliceItems(['a', 'b'], { start: 1, remove: 1, insert: ['B'] }, 2)).toEqual(['a', 'B']);
    });

    it('never changes the input', () => {
        const items = ['a', 'b'];
        spliceItems(items, { start: 0, remove: 1, insert: ['x', 'y'] }, 10);
        expect(items).toEqual(['a', 'b']);
    });
});

describe('moveItem / removeItem', () => {
    it('moves within bounds and removes by index', () => {
        expect(moveItem(['a', 'b', 'c'], 0, 2)).toEqual(['b', 'c', 'a']);
        expect(moveItem(['a', 'b'], 0, 5)).toEqual(['a', 'b']);
        expect(removeItem(['a', 'b', 'c'], 1)).toEqual(['a', 'c']);
    });
});
