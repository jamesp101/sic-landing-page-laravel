/**
 * The one rule for which chords undo and redo in every admin editor (block editor, Slide designer,
 * org chart, navigation links): Ctrl/Cmd+Z undoes; Ctrl/Cmd+Shift+Z and Ctrl/Cmd+Y redo. Each editor keeps
 * its own undo engine and decides when the keys apply.
 */

import { matchesChord, type Chord, type KeyEventLike } from './keymap-core';

export const UNDO_CHORDS: readonly Chord[] = [{ key: 'z', mod: true }];
export const REDO_CHORDS: readonly Chord[] = [
    { key: 'z', mod: true, shift: true },
    { key: 'y', mod: true },
];

/** Whether the key event is an undo chord, a redo chord, or neither. */
export function undoRedoOf(event: KeyEventLike): 'undo' | 'redo' | null {
    if (UNDO_CHORDS.some((chord) => matchesChord(chord, event))) return 'undo';
    if (REDO_CHORDS.some((chord) => matchesChord(chord, event))) return 'redo';
    return null;
}

/** Whether a key event's target is a field or dialog that keeps its own undo (or editing) keys. */
export function isTypingTarget(target: EventTarget | null): boolean {
    return (
        target instanceof Element &&
        target.closest('input, textarea, select, [contenteditable="true"], [role="dialog"]') !== null
    );
}
