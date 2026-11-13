import { describe, expect, test } from 'vitest';
import { canRedo, canUndo, commit, createHistory, HISTORY_LIMIT, redo, undo } from './undo-history';

describe('undo history', () => {
    test('undo and redo walk the stack and a new commit clears redo', () => {
        let h = createHistory(0);
        h = commit(h, 1);
        h = commit(h, 2);
        h = undo(h);
        expect(h.present).toBe(1);
        expect(canRedo(h)).toBe(true);
        h = redo(h);
        expect(h.present).toBe(2);
        h = undo(undo(h));
        expect(h.present).toBe(0);
        expect(canUndo(h)).toBe(false);
        expect(undo(h)).toBe(h);
        h = commit(h, 9);
        expect(h.future).toEqual([]);
        expect(redo(h)).toBe(h);
    });

    test('same-key edits inside the window coalesce into one undo step', () => {
        let h = createHistory('');
        h = commit(h, 'h', 'text', 0);
        h = commit(h, 'he', 'text', 100);
        h = commit(h, 'hel', 'text', 200);
        expect(h.past).toEqual(['']);
        expect(undo(h).present).toBe('');
    });

    test('does not coalesce across keys, after the window, or without a key', () => {
        let h = createHistory('');
        h = commit(h, 'a', 'one', 0);
        h = commit(h, 'ab', 'two', 100);
        h = commit(h, 'abc', 'two', 5000);
        expect(h.past).toEqual(['', 'a', 'ab']);
        h = commit(h, 'abcd', null, 5001);
        h = commit(h, 'abcde', null, 5002);
        expect(h.past).toHaveLength(5);
    });

    test('keeps only the most recent HISTORY_LIMIT steps', () => {
        let h = createHistory(0);
        for (let i = 1; i <= HISTORY_LIMIT + 50; i++) h = commit(h, i, null, 0);
        expect(h.past).toHaveLength(HISTORY_LIMIT);
        expect(h.past[0]).toBe(50);
    });
});
