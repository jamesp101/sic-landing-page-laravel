/**
 * Text typed on the editor canvas → a props patch. The canvas only knows the `data-edit` mark of the element
 * (`ctx.field`: a prop path such as `items.2.title`, plus `data-edit-part` for one paragraph of a multi-paragraph
 * prop); this module writes the value back into a copy of the block's props. List items (`data-edit-kind="item"`)
 * can also be split and joined (`itemsEditPatch`).
 */
import { paragraphsOf } from './inline';

export interface InlineEdit {
    /** `data-edit` of the element: prop path segments joined with `.`. */
    path: string;
    /** `data-edit-part`: index of the edited paragraph (`paragraphsOf`), `null` for the whole prop. */
    part: number | null;
    value: string;
}

/** `'items.2.title'` → `['items', 2, 'title']`. */
export function parseEditPath(path: string): (string | number)[] {
    return path.split('.').map((segment) => (/^\d+$/.test(segment) ? Number(segment) : segment));
}

/** Replaces one paragraph; an emptied paragraph is dropped, a typed blank line starts a new one. */
function replacePart(text: string, part: number, value: string): string {
    const parts = paragraphsOf(text);
    if (part < 0 || part >= parts.length) return text;
    parts[part] = value;
    return parts
        .map((p) => p.trim())
        .filter(Boolean)
        .join('\n\n');
}

/** A stored prop value (block props are JSON). */
type PropValue = string | number | boolean | null | PropValue[] | { [key: string]: PropValue };

/** Copy of `node` with the string at `path` updated; `undefined` when `path` does not end at a string. */
function setAt(node: PropValue, path: (string | number)[], update: (current: string) => string): PropValue | undefined {
    if (path.length === 0) return typeof node === 'string' ? update(node) : undefined;
    const [head, ...rest] = path;
    if (Array.isArray(node)) {
        if (typeof head !== 'number' || head >= node.length) return undefined;
        const next = setAt(node[head], rest, update);
        if (next === undefined) return undefined;
        const copy = [...node];
        copy[head] = next;
        return copy;
    }
    if (node !== null && typeof node === 'object' && typeof head === 'string' && Object.hasOwn(node, head)) {
        const next = setAt(node[head], rest, update);
        return next === undefined ? undefined : { ...node, [head]: next };
    }
    return undefined;
}

/**
 * The patch for `setProps` (`{ <top-level prop>: <new value> }`), or `null` when the path does not lead to a string
 * prop of these props (stale markup) or nothing changed.
 */
export function inlineEditPatch<P extends object>(props: P, edit: InlineEdit): Partial<P> | null {
    const [key, ...rest] = parseEditPath(edit.path);
    if (typeof key !== 'string' || !Object.hasOwn(props, key)) return null;
    // SAFETY: block props are schema-normalised JSON (strings, numbers, booleans, null, arrays, plain objects), so every
    // value is a `PropValue`; the props interfaces just lack the index signature that would let TypeScript see it.
    const current = (props as unknown as Record<string, PropValue>)[key];
    const next = setAt(current, rest, (text) =>
        edit.part === null ? edit.value : replacePart(text, edit.part, edit.value),
    );
    if (next === undefined || JSON.stringify(next) === JSON.stringify(current)) return null;
    return { [key]: next } as Partial<P>;
}

/** Replaces `remove` entries of a top-level string list (`path`, e.g. `items`) from `start` with `insert`. */
export interface ItemsEdit {
    path: string;
    start: number;
    remove: number;
    insert: string[];
}

/** `'items.3'` → `{ path: 'items', index: 3 }`; `null` for anything but an entry of a top-level list. */
export function itemOf(path: string): { path: string; index: number } | null {
    const match = /^([A-Za-z]\w*)\.(\d+)$/.exec(path);
    return match ? { path: match[1], index: Number(match[2]) } : null;
}

/**
 * The patch for `setProps`, or `null` when `path` is not a string list of these props, the range is outside it, or
 * the list would grow past `max` entries.
 */
export function itemsEditPatch<P extends object>(props: P, edit: ItemsEdit, max: number): Partial<P> | null {
    if (!Object.hasOwn(props, edit.path)) return null;
    // SAFETY: as in `inlineEditPatch`, block props are schema-normalised JSON.
    const current = (props as unknown as Record<string, PropValue>)[edit.path];
    if (!Array.isArray(current) || !current.every((item) => typeof item === 'string')) return null;
    const { start, remove, insert } = edit;
    if (start < 0 || remove < 0 || start + remove > current.length) return null;
    const next = [...current.slice(0, start), ...insert, ...current.slice(start + remove)];
    if (next.length > max) return null;
    return { [edit.path]: next } as Partial<P>;
}

const BULLET = /^\s*(?:[-*+•‣◦▪–]|\d{1,3}[.)])\s+/;

/** Pasted text → one entry per non-blank line, without a leading bullet or number (`- `, `• `, `1. `, `2) `). */
export function pastedItems(text: string): string[] {
    return text
        .split(/\r\n?|\n/)
        .map((line) => line.replace(BULLET, '').trim())
        .filter(Boolean);
}
