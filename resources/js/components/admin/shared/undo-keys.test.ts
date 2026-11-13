import { describe, expect, it } from 'vitest';
import type { KeyEventLike } from './keymap-core';
import { undoRedoOf } from './undo-keys';
import { SHORTCUTS } from '../editor/keymap';
import { DESIGNER_SHORTCUTS } from '../carousels/designer-keymap';
import { REDO_CHORDS, UNDO_CHORDS } from './undo-keys';

const press = (key: string, mods: Partial<Omit<KeyEventLike, 'key'>> = {}): KeyEventLike => ({
    key,
    ctrlKey: false,
    metaKey: false,
    shiftKey: false,
    altKey: false,
    ...mods,
});

describe('undoRedoOf', () => {
    it.each([
        ['z', { ctrlKey: true }, 'undo'],
        ['Z', { metaKey: true }, 'undo'],
        ['z', { ctrlKey: true, shiftKey: true }, 'redo'],
        ['Z', { metaKey: true, shiftKey: true }, 'redo'],
        ['y', { ctrlKey: true }, 'redo'],
        ['y', { metaKey: true }, 'redo'],
        ['z', {}, null],
        ['y', {}, null],
        ['y', { ctrlKey: true, shiftKey: true }, null],
        ['z', { ctrlKey: true, altKey: true }, null],
        ['x', { ctrlKey: true }, null],
    ] as const)('%s with %o is %s', (key, mods, expected) => {
        expect(undoRedoOf(press(key, mods))).toBe(expected);
    });
});

describe('editor key tables', () => {
    it('use the shared undo and redo chords', () => {
        for (const table of [SHORTCUTS, DESIGNER_SHORTCUTS] as const) {
            expect(table.find((s) => s.command === 'undo')?.chords).toBe(UNDO_CHORDS);
            expect(table.find((s) => s.command === 'redo')?.chords).toBe(REDO_CHORDS);
        }
    });
});
