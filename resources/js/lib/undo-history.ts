/**
 * Linear undo/redo history shared by the block editor's Editing session and the slide designer's Slide drafts.
 * Immutable: every function returns a new history (or the same one when there is nothing to do).
 * Edits sharing a `key` within `COALESCE_MS` collapse into one undo step (typing, sliders).
 */
export interface UndoHistory<T> {
    past: T[];
    present: T;
    future: T[];
    key: string | null;
    at: number;
}

export const HISTORY_LIMIT = 200;
export const COALESCE_MS = 800;

export function createHistory<T>(present: T): UndoHistory<T> {
    return { past: [], present, future: [], key: null, at: 0 };
}

export const canUndo = (history: UndoHistory<unknown>): boolean => history.past.length > 0;
export const canRedo = (history: UndoHistory<unknown>): boolean => history.future.length > 0;

export function commit<T>(
    history: UndoHistory<T>,
    next: T,
    key: string | null = null,
    now = Date.now(),
): UndoHistory<T> {
    const coalesce = key !== null && key === history.key && now - history.at < COALESCE_MS && history.past.length > 0;
    const past = coalesce ? history.past : [...history.past, history.present].slice(-HISTORY_LIMIT);
    return { past, present: next, future: [], key, at: now };
}

export function undo<T>(history: UndoHistory<T>): UndoHistory<T> {
    if (history.past.length === 0) return history;
    const present = history.past[history.past.length - 1];
    return { past: history.past.slice(0, -1), present, future: [history.present, ...history.future], key: null, at: 0 };
}

export function redo<T>(history: UndoHistory<T>): UndoHistory<T> {
    if (history.future.length === 0) return history;
    const [present, ...future] = history.future;
    return { past: [...history.past, history.present], present, future, key: null, at: 0 };
}
