/**
 * Imperative controller for the editor canvas: an iframe that renders the block document with the
 * site's real stylesheet (so Tailwind breakpoints, dark mode and tokens behave exactly as on the public
 * page). It owns selection/hover outlines, the drag handle, drop indicators, inline text editing and
 * pointer drag & drop. All document changes go back to the host through callbacks.
 *
 * Inline editing: every text the renderer marks with `ctx.field` (`data-edit`, `data-edit-kind`, `data-edit-part`)
 * can be typed into. Double-click a text, or click a text of the selected block, to type; Tab / Shift+Tab move to the
 * block's next / previous text; Enter (Ctrl+Enter in rich text) or clicking away saves; Escape cancels.
 * In a list item (`data-edit-kind="item"`) Enter starts a new item, pasted lines become items, and Backspace at the
 * start joins the item to the one above.
 */
import { CONTAINER_TYPES } from '@/lib/blocks/registry';
import type { DropSource, DropTarget } from '@/lib/blocks/edit';
import { escapeInline } from '@/lib/blocks/inline';
import { itemOf, pastedItems, type InlineEdit, type ItemsEdit } from '@/lib/blocks/inline-edit';
import { domToInline, domToPlain } from './inline-dom';

/** What a canvas drag carries: the drop rule's source plus the ghost's label. */
export type CanvasSource = DropSource & { label: string };

export interface CanvasHost {
    select(id: string | null): void;
    /** Inline edit of block `id` finished with a changed value. */
    commitText(id: string, edit: InlineEdit): void;
    /** Splits or joins list items of block `id`; false when refused (e.g. the list is full). */
    spliceItems(id: string, edit: ItemsEdit): boolean;
    key(event: KeyboardEvent, editing: boolean): void;
    drop(source: CanvasSource, target: DropTarget): void;
    canDrop(source: CanvasSource, parentId: string | null): boolean;
    /** Whether a drag is in progress (to suppress hover chrome in the host). */
    dragging(active: boolean): void;
}

const EDITOR_CSS = `
html{scroll-behavior:auto}
body{min-height:100vh;margin:0}
[data-block-id]{position:relative}
[data-block-id][data-hover]:not([data-selected]){outline:1px dashed var(--ring);outline-offset:2px}
[data-block-id][data-selected]{outline:2px solid var(--ring);outline-offset:2px}
[data-block-id][data-drop-target]{outline:2px dashed var(--gold-500);outline-offset:2px;background-image:linear-gradient(color-mix(in srgb,var(--gold-500) 8%,transparent),color-mix(in srgb,var(--gold-500) 8%,transparent))}
[data-block-id][data-dragging]{opacity:.35}
[data-empty]{min-height:4.5rem;border:1px dashed var(--border-strong);border-radius:.75rem;align-items:center;justify-content:center}
[data-empty]::after{content:'Drop elements here';color:var(--muted-foreground);font-size:12px;margin:auto;padding:1rem}
[contenteditable]{outline:2px solid var(--gold-500)!important;outline-offset:2px;cursor:text;min-width:1ch}
[data-selected][data-edit]:not([contenteditable]),[data-selected] [data-edit]:not([contenteditable]){cursor:text}
[data-selected][data-edit]:not([contenteditable]):hover,[data-selected] [data-edit]:not([contenteditable]):hover{outline:1px dashed var(--gold-500);outline-offset:2px}
.sic-chip{position:absolute;z-index:40;display:flex;align-items:center;gap:4px;height:22px;padding:0 8px 0 4px;border-radius:6px 6px 6px 0;background:var(--ring);color:var(--primary-foreground);font:600 11px/22px var(--font-sans,system-ui),system-ui,sans-serif;cursor:grab;user-select:none;white-space:nowrap}
.sic-chip:active{cursor:grabbing}
.sic-indicator{position:absolute;z-index:45;pointer-events:none;background:var(--gold-500);border-radius:2px;box-shadow:0 0 0 1px var(--surface)}
details>summary{cursor:pointer}
`;

const CONTAINERS: Record<string, true> = Object.fromEntries(CONTAINER_TYPES.map((type) => [type, true]));
const EDGE = 10;
const DRAG_THRESHOLD = 5;

/**
 * Builds the iframe document: the page's own styles plus the editor chrome. The root uses the public page frame
 * (`app.css`): `reading` is the Post body, `page` a content section, so the preview has the site's width and measure.
 */
export function buildSrcdoc(options: { frame: 'reading' | 'page'; dark: boolean }): string {
    const styles = Array.from(document.querySelectorAll('link[rel="stylesheet"], style'))
        .map((node) => node.outerHTML)
        .join('\n');
    const frame = options.frame === 'reading' ? 'reading-frame' : 'page-frame';
    return `<!doctype html><html lang="en" class="${options.dark ? 'dark' : ''}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${styles}<style>${EDITOR_CSS}</style></head><body class="bg-background font-sans text-foreground antialiased"><div id="sic-root" class="${frame} py-8 text-base leading-relaxed text-foreground"></div></body></html>`;
}

function blockOf(element: Element | null): HTMLElement | null {
    return element?.closest<HTMLElement>('[data-block-id]') ?? null;
}

/** Block elements nested directly inside `scope` (a block, or the root when `null`). */
function childBlocks(root: HTMLElement, scope: HTMLElement | null): HTMLElement[] {
    const base = scope ?? root;
    return Array.from(base.querySelectorAll<HTMLElement>('[data-block-id]')).filter((el) => {
        const parent = blockOf(el.parentElement);
        return scope ? parent === scope : parent === null;
    });
}

/** Which text of a block an element shows: its `data-edit` path and paragraph. */
type EditingKind = 'plain' | 'rich' | 'item';

/** The text being typed. */
interface Editing {
    id: string;
    el: HTMLElement;
    kind: EditingKind;
    field: FieldRef;
    /** The text when typing started, to skip saving an unchanged text. */
    before: string;
}

/** Where the caret goes when typing starts: under a pointer, or after `offset` characters of the text. */
type Caret = { x: number; y: number } | { offset: number };

interface FieldRef {
    path: string;
    part: number | null;
}

function fieldRef(el: HTMLElement): FieldRef {
    const part = el.dataset.editPart;
    return { path: el.dataset.edit ?? '', part: part === undefined ? null : Number(part) };
}

/** The typeable texts of a block (not those of blocks nested in it), in document order. */
function fieldsOf(block: HTMLElement): HTMLElement[] {
    const inside = Array.from(block.querySelectorAll<HTMLElement>('[data-edit]')).filter((el) => blockOf(el) === block);
    return block.hasAttribute('data-edit') ? [block, ...inside] : inside;
}

function editingKind(el: HTMLElement): EditingKind {
    const kind = el.dataset.editKind;
    return kind === 'rich' || kind === 'item' ? kind : 'plain';
}

/** The stored form of a text element: inline markup, or plain text for plain texts. */
function textOf(el: HTMLElement, kind: EditingKind): string {
    return kind === 'plain' ? domToPlain(el) : domToInline(el);
}

/** Inline markup of the part of a text a range covers (formatting cut by the range is kept). */
function inlineOfRange(doc: Document, range: Range): string {
    const box = doc.createElement('div');
    box.append(range.cloneContents());
    return domToInline(box);
}

/** A collapsed range after `offset` characters of `el`'s text (clamped to its end). */
function rangeAtOffset(doc: Document, el: HTMLElement, offset: number): Range {
    const range = doc.createRange();
    const walker = doc.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    let left = offset;
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        const length = node.nodeValue?.length ?? 0;
        if (left <= length) {
            range.setStart(node, left);
            return range;
        }
        left -= length;
    }
    range.selectNodeContents(el);
    range.collapse(false);
    return range;
}

/**
 * Where a drop at (x, y) may go, innermost first: the container blocks under the pointer (not the moved block or its
 * descendants, and not when the pointer is near the container's border: then the drop goes beside it), then the root
 * (`null`).
 */
function dropCandidates(doc: Document, x: number, y: number, moving: HTMLElement | null): (HTMLElement | null)[] {
    const candidates: (HTMLElement | null)[] = [];
    for (let el = blockOf(doc.elementFromPoint(x, y)); el; el = blockOf(el.parentElement)) {
        const isMoving = moving !== null && (el === moving || moving.contains(el));
        const isContainer = CONTAINERS[el.dataset.blockType ?? ''] === true;
        if (!isMoving && isContainer && !nearEdge(el.getBoundingClientRect(), x, y)) candidates.push(el);
    }
    candidates.push(null);
    return candidates;
}

function nearEdge(rect: DOMRect, x: number, y: number): boolean {
    return x - rect.left < EDGE || rect.right - x < EDGE || y - rect.top < EDGE || rect.bottom - y < EDGE;
}

/** The block closest to (x, y); `kids` is not empty. */
function nearestBlock(kids: HTMLElement[], x: number, y: number): HTMLElement {
    let nearest = kids[0];
    let best = Infinity;
    for (const kid of kids) {
        const distance = distanceTo(kid.getBoundingClientRect(), x, y);
        if (distance < best) {
            best = distance;
            nearest = kid;
        }
    }
    return nearest;
}

/** Whether a container places its children side by side: a row flexbox, or a grid whose first two share a row. */
function laysOutInRow(style: CSSStyleDeclaration, kids: HTMLElement[]): boolean {
    if (style.display.includes('flex')) return style.flexDirection.startsWith('row');
    if (!style.display.includes('grid') || kids.length < 2) return false;
    const a = kids[0].getBoundingClientRect();
    const b = kids[1].getBoundingClientRect();
    return a.top < b.bottom && b.top < a.bottom;
}

/** The drop indicator beside a block (page coordinates): a vertical bar in a row, else a horizontal one. */
function indicatorLine(win: Window, rect: DOMRect, horizontal: boolean, before: boolean) {
    if (horizontal) {
        const left = before ? rect.left : rect.right;
        return { left: left + win.scrollX - 2, top: rect.top + win.scrollY, width: 4, height: rect.height };
    }
    const top = before ? rect.top : rect.bottom;
    return { left: rect.left + win.scrollX, top: top + win.scrollY - 2, width: rect.width, height: 4 };
}

/** A collapsed range at the text position under a point (frame coordinates), if it falls inside `el`. */
function rangeAtPoint(doc: Document, el: HTMLElement, point: { x: number; y: number }): Range | null {
    const position = doc.caretPositionFromPoint?.(point.x, point.y);
    if (position && el.contains(position.offsetNode)) {
        const range = doc.createRange();
        range.setStart(position.offsetNode, position.offset);
        return range;
    }
    // Fallback for browsers without caretPositionFromPoint (older Safari), hence the deprecated API.
    const legacy = doc.caretRangeFromPoint?.(point.x, point.y);
    return legacy && el.contains(legacy.startContainer) ? legacy : null;
}

function distanceTo(rect: DOMRect, x: number, y: number): number {
    const dx = Math.max(rect.left - x, 0, x - rect.right);
    const dy = Math.max(rect.top - y, 0, y - rect.bottom);
    return Math.hypot(dx, dy);
}

export class CanvasController {
    #iframe: HTMLIFrameElement;
    #host: CanvasHost;
    #doc: Document | null = null;
    #root: HTMLElement | null = null;
    #chip: HTMLElement | null = null;
    #indicator: HTMLElement | null = null;
    #selected: string | null = null;
    #hover: HTMLElement | null = null;
    #html = '';
    #editing: Editing | null = null;
    #drag: {
        source: CanvasSource;
        ghost: HTMLElement;
        target: DropTarget | null;
        started: boolean;
        startX: number;
        startY: number;
    } | null = null;
    #resize: ResizeObserver | null = null;
    #cleanup: (() => void)[] = [];

    constructor(iframe: HTMLIFrameElement, host: CanvasHost) {
        this.#iframe = iframe;
        this.#host = host;
    }

    get editing(): boolean {
        return this.#editing !== null;
    }

    /** Call from the iframe's `load` event. */
    attach(): void {
        const doc = this.#iframe.contentDocument;
        const win = this.#iframe.contentWindow;
        if (!doc || !win) return;
        this.detachListeners();
        this.#doc = doc;
        this.#root = doc.getElementById('sic-root');

        const on = <K extends keyof DocumentEventMap>(type: K, handler: (event: DocumentEventMap[K]) => void) => {
            doc.addEventListener(type, handler as EventListener);
            this.#cleanup.push(() => doc.removeEventListener(type, handler as EventListener));
        };
        on('click', (event) => this.#onClick(event));
        on('dblclick', (event) => this.#onDoubleClick(event));
        on('mouseover', (event) => this.#onHover(event));
        on('mouseleave', () => this.setHover(null));
        on('keydown', (event) => this.#onKeyDown(event));
        on('submit', (event) => event.preventDefault());
        // Browsers navigate on dropped files; the canvas never accepts them.
        on('dragover', (event) => event.preventDefault());
        on('drop', (event) => event.preventDefault());

        this.#resize = new ResizeObserver(() => this.#positionChip());
        if (this.#root) this.#resize.observe(this.#root);
        if (this.#html) this.render(this.#html);
    }

    detachListeners(): void {
        for (const fn of this.#cleanup) fn();
        this.#cleanup = [];
        this.#resize?.disconnect();
    }

    destroy(): void {
        this.detachListeners();
        this.#endDrag(false);
    }

    setDark(dark: boolean): void {
        this.#doc?.documentElement.classList.toggle('dark', dark);
    }

    render(html: string): void {
        this.#html = html;
        if (!this.#root || this.#editing || this.#drag?.started) return;
        const scroller = this.#doc?.scrollingElement;
        const top = scroller?.scrollTop ?? 0;
        this.#root.innerHTML = html;
        if (scroller) scroller.scrollTop = top;
        this.#applySelection();
    }

    setSelected(id: string | null): void {
        this.#selected = id;
        this.#applySelection();
    }

    setHover(id: string | HTMLElement | null): void {
        const next = typeof id === 'string' ? (this.#find(id) ?? null) : id;
        if (next === this.#hover) return;
        this.#hover?.removeAttribute('data-hover');
        this.#hover = next;
        next?.setAttribute('data-hover', '');
    }

    scrollToBlock(id: string): void {
        const win = this.#iframe.contentWindow;
        const rect = this.#find(id)?.getBoundingClientRect();
        if (!win || !rect) return;
        // Scroll inside the canvas only (scrollIntoView would also scroll the admin page).
        if (rect.top < 24) win.scrollBy(0, rect.top - 24);
        else if (rect.bottom > win.innerHeight - 24)
            win.scrollBy(0, Math.min(rect.bottom - win.innerHeight + 24, rect.top - 24));
    }

    /**
     * Puts a text of the block into inline-edit mode: `field` (default: the block's first text), with the caret at
     * `caret` (frame coordinates or a character offset) or at the end. Returns false when the block has no typeable text.
     */
    startEdit(id: string, field?: FieldRef, caret?: Caret): boolean {
        const el = this.#find(id);
        if (!el || this.#editing) return false;
        const fields = fieldsOf(el);
        const target = field
            ? fields.find((f) => {
                  const ref = fieldRef(f);
                  return ref.path === field.path && ref.part === field.part;
              })
            : fields[0];
        if (!target) return false;

        const kind = editingKind(target);
        const before = textOf(target, kind);
        this.#editing = { id, el: target, kind, field: fieldRef(target), before };
        target.setAttribute('contenteditable', kind === 'plain' ? 'plaintext-only' : 'true');
        target.spellcheck = true;
        target.focus({ preventScroll: true });
        this.#placeCaret(target, caret);

        target.addEventListener('blur', this.#onBlur);
        target.addEventListener('paste', this.#onPaste);
        return true;
    }

    stopEdit(): void {
        this.#finishEdit(true);
    }

    #placeCaret(target: HTMLElement, caret?: Caret): void {
        const doc = target.ownerDocument;
        let range: Range | null = null;
        if (caret && 'offset' in caret) range = rangeAtOffset(doc, target, caret.offset);
        else if (caret) range = rangeAtPoint(doc, target, caret);
        const placed = range !== null;
        if (!range) {
            range = doc.createRange();
            range.selectNodeContents(target);
        }
        range.collapse(placed && range.collapsed);
        const selection = this.#iframe.contentWindow?.getSelection();
        selection?.removeAllRanges();
        selection?.addRange(range);
    }

    /** Saves the current text and continues in the block's next (or previous) text. */
    #moveEdit(step: 1 | -1): void {
        const editing = this.#editing;
        const block = editing ? this.#find(editing.id) : null;
        if (!editing || !block) return;
        const fields = fieldsOf(block);
        const next = fields[fields.indexOf(editing.el) + step];
        const ref = next ? fieldRef(next) : null;
        this.#finishEdit(true);
        // After the host has re-rendered the saved text.
        if (ref) setTimeout(() => this.startEdit(editing.id, ref), 0);
    }

    #onBlur = () => this.#finishEdit(true);

    #onPaste = (event: ClipboardEvent) => {
        event.preventDefault();
        const text = event.clipboardData?.getData('text/plain') ?? '';
        // Several pasted lines in a list item become several items.
        if (this.#editing?.kind === 'item' && this.#pasteItems(this.#editing, pastedItems(text))) return;
        this.#doc?.execCommand(
            'insertText',
            false,
            this.#editing?.kind === 'plain' ? text.replace(/\s*\n\s*/g, ' ') : text,
        );
    };

    /** Leaves inline-edit mode without saving or re-rendering. */
    #endEditing(editing: Editing): void {
        this.#editing = null;
        editing.el.removeEventListener('paste', this.#onPaste);
        // Removed here, not `once`: a later blur of this element must not end the edit of the next text.
        editing.el.removeEventListener('blur', this.#onBlur);
        editing.el.removeAttribute('contenteditable');
    }

    #finishEdit(commit: boolean): void {
        const editing = this.#editing;
        if (!editing) return;
        this.#endEditing(editing);
        const value = textOf(editing.el, editing.kind);
        if (commit && value !== editing.before) {
            // The host re-renders with the new model right after this.
            this.#host.commitText(editing.id, { ...editing.field, value });
            return;
        }
        // Nothing changed: restore the rendered markup so the DOM matches the model again.
        this.render(this.#html);
    }

    #onKeyDown(event: KeyboardEvent): void {
        if (!this.#editing) {
            this.#host.key(event, false);
            return;
        }
        if (event.key === 'Tab') {
            event.preventDefault();
            this.#moveEdit(event.shiftKey ? -1 : 1);
            return;
        }
        if (this.#editing.kind === 'item' && this.#onItemKey(event, this.#editing)) return;
        this.#onEditingKey(event, this.#editing);
        this.#host.key(event, true);
    }

    // -----------------------------------------------------------------------------------------
    // List items: Enter splits, pasted lines become items, Backspace at the start joins
    // -----------------------------------------------------------------------------------------

    /** Enter (new item) and Backspace at the start (join with the item above); true when handled. */
    #onItemKey(event: KeyboardEvent, editing: Editing): boolean {
        const plainKey = !event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey;
        const listKey = event.key === 'Enter' || event.key === 'Backspace';
        const around = plainKey && listKey ? this.#textAround(editing.el) : null;
        const item = itemOf(editing.field.path);
        if (!around || !item) return false;
        if (event.key === 'Enter') {
            event.preventDefault();
            // A full list refuses the split and keeps the caret where it is.
            this.#spliceItems(
                editing,
                { path: item.path, start: item.index, remove: 1, insert: [around.before, around.after] },
                item.index + 1,
                0,
            );
            return true;
        }
        if (event.key !== 'Backspace' || !around.atStart) return false;
        return this.#joinWithPrevious(event, editing, item);
    }

    #joinWithPrevious(event: KeyboardEvent, editing: Editing, item: { path: string; index: number }): boolean {
        const block = this.#find(editing.id);
        const previousPath = `${item.path}.${item.index - 1}`;
        const previous = block ? fieldsOf(block).find((el) => fieldRef(el).path === previousPath) : undefined;
        if (!previous) return false;
        event.preventDefault();
        const joined = domToInline(previous) + textOf(editing.el, 'item');
        return this.#spliceItems(
            editing,
            { path: item.path, start: item.index - 1, remove: 2, insert: [joined] },
            item.index - 1,
            previous.textContent?.length ?? 0,
        );
    }

    /** Pasted lines (at least two) replace the selection: the first joins the text before it, the last the text after. */
    #pasteItems(editing: Editing, lines: string[]): boolean {
        const around = this.#textAround(editing.el);
        const item = itemOf(editing.field.path);
        if (lines.length < 2 || !around || !item) return false;
        const insert = lines.map(escapeInline);
        insert[0] = around.before + insert[0];
        insert.push(`${insert.pop() ?? ''}${around.after}`);
        return this.#spliceItems(
            editing,
            { path: item.path, start: item.index, remove: 1, insert },
            item.index + lines.length - 1,
            lines.at(-1)?.length ?? 0,
        );
    }

    /** The inline markup before and after the selection in `el`; `atStart` when the caret is at its very start. */
    #textAround(el: HTMLElement): { before: string; after: string; atStart: boolean } | null {
        const doc = this.#doc;
        const selection = this.#iframe.contentWindow?.getSelection();
        const range = selection && selection.rangeCount > 0 ? selection.getRangeAt(0) : null;
        if (!doc || !range || !el.contains(range.startContainer) || !el.contains(range.endContainer)) return null;
        const head = doc.createRange();
        head.selectNodeContents(el);
        head.setEnd(range.startContainer, range.startOffset);
        const tail = doc.createRange();
        tail.selectNodeContents(el);
        tail.setStart(range.endContainer, range.endOffset);
        return {
            before: inlineOfRange(doc, head).trimEnd(),
            after: inlineOfRange(doc, tail).trimStart(),
            atStart: range.collapsed && head.toString() === '',
        };
    }

    /** Hands a split or join to the host, then continues typing in item `index` after `offset` characters. */
    #spliceItems(editing: Editing, edit: ItemsEdit, index: number, offset: number): boolean {
        if (!this.#host.spliceItems(editing.id, edit)) return false;
        this.#endEditing(editing);
        // After the host has re-rendered the list.
        setTimeout(() => this.startEdit(editing.id, { path: `${edit.path}.${index}`, part: null }, { offset }), 0);
        return true;
    }

    /** Keys while typing: save, cancel, line break; Ctrl/⌘ chords go to `#onFormatKey`. */
    #onEditingKey(event: KeyboardEvent, editing: Editing): void {
        const mod = event.ctrlKey || event.metaKey;
        if (event.key === 'Escape') {
            event.preventDefault();
            this.#finishEdit(false);
        } else if (event.key === ' ' && editing.el.closest('summary')) {
            // A space in an accordion title would toggle the item instead of typing.
            event.preventDefault();
            this.#doc?.execCommand('insertText', false, ' ');
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (editing.kind === 'plain' || mod) this.#finishEdit(true);
            else this.#doc?.execCommand('insertLineBreak');
        } else if (mod) {
            this.#onFormatKey(event, editing.kind);
        }
    }

    /** Ctrl/⌘+K adds a link in rich text; plain texts take no bold, italic or underline. */
    #onFormatKey(event: KeyboardEvent, kind: EditingKind): void {
        const key = event.key.toLowerCase();
        if (key === 'k' && kind !== 'plain') {
            event.preventDefault();
            const url = this.#iframe.contentWindow?.prompt('Link address (https://…)', 'https://');
            if (url) this.#doc?.execCommand('createLink', false, url);
        } else if (kind === 'plain' && ['b', 'i', 'u'].includes(key)) {
            event.preventDefault();
        }
    }

    #onClick(event: MouseEvent): void {
        if (this.#drag?.started) return;
        const target = event.target as Element | null;
        const inEditing = this.#editing?.el.contains(target);
        if (inEditing) return;
        if (target?.closest('.sic-chip')) {
            event.preventDefault();
            return;
        }
        // A click on a text of the selected block starts typing there.
        const block = blockOf(target);
        const field = this.#fieldAt(target, block);
        if (block && field && block.dataset.blockId === this.#selected) {
            event.preventDefault();
            this.#editAt(block.dataset.blockId, field, event);
            return;
        }
        if (!target?.closest('summary')) event.preventDefault();
        this.#host.select(block?.dataset.blockId ?? null);
    }

    #onDoubleClick(event: MouseEvent): void {
        const target = event.target as Element | null;
        const block = blockOf(target);
        if (!block?.dataset.blockId) return;
        this.#host.select(block.dataset.blockId);
        this.#editAt(block.dataset.blockId, this.#fieldAt(target, block), event);
    }

    /** The text of `block` under `target`, if any. */
    #fieldAt(target: Element | null, block: HTMLElement | null): FieldRef | undefined {
        const field = target?.closest<HTMLElement>('[data-edit]');
        return field && block && blockOf(field) === block ? fieldRef(field) : undefined;
    }

    #editAt(id: string, field: FieldRef | undefined, event: MouseEvent): void {
        const point = { x: event.clientX, y: event.clientY };
        // Wait one tick so the selection (and the re-render of any text just saved) finishes first.
        setTimeout(() => this.startEdit(id, field, field ? point : undefined), 0);
    }

    #onHover(event: MouseEvent): void {
        if (this.#drag?.started) return;
        this.setHover(blockOf(event.target as Element | null));
    }

    #find(id: string): HTMLElement | null {
        return this.#root?.querySelector<HTMLElement>(`[data-block-id="${CSS.escape(id)}"]`) ?? null;
    }

    #applySelection(): void {
        if (!this.#doc || !this.#root) return;
        this.#hover = null;
        for (const el of this.#root.querySelectorAll('[data-selected]')) el.removeAttribute('data-selected');
        const selected = this.#selected ? this.#find(this.#selected) : null;
        selected?.setAttribute('data-selected', '');
        this.#positionChip();
    }

    #positionChip(): void {
        const doc = this.#doc;
        const win = this.#iframe.contentWindow;
        if (!doc || !win) return;
        const selected = this.#selected ? this.#find(this.#selected) : null;
        if (!selected || this.#editing) {
            this.#chip?.remove();
            this.#chip = null;
            return;
        }
        if (!this.#chip) {
            const chip = doc.createElement('div');
            chip.className = 'sic-chip';
            chip.setAttribute('data-chip', '');
            chip.setAttribute('title', 'Drag to move');
            chip.addEventListener('pointerdown', (event) => this.#onChipPointerDown(event));
            doc.body.append(chip);
            this.#chip = chip;
        }
        const label = selected.dataset.blockLabel ?? 'Block';
        this.#chip.innerHTML = '';
        const grip = doc.createElement('span');
        grip.setAttribute('aria-hidden', 'true');
        grip.textContent = '⠿';
        const text = doc.createElement('span');
        text.textContent = label;
        this.#chip.append(grip, text);
        const rect = selected.getBoundingClientRect();
        const top = rect.top + win.scrollY;
        this.#chip.style.left = `${Math.max(0, rect.left + win.scrollX - 2)}px`;
        this.#chip.style.top = `${top < 26 ? top + 2 : top - 24}px`;
    }

    // -----------------------------------------------------------------------------------------
    // Drag & drop (palette → canvas and canvas move)
    // -----------------------------------------------------------------------------------------

    #onChipPointerDown(event: PointerEvent): void {
        if (event.button !== 0 || !this.#selected) return;
        event.preventDefault();
        const rect = this.#iframe.getBoundingClientRect();
        const scale = rect.width / this.#iframe.offsetWidth || 1;
        this.beginDrag(
            { kind: 'move', id: this.#selected, label: this.#find(this.#selected)?.dataset.blockLabel ?? 'Block' },
            rect.left + event.clientX * scale,
            rect.top + event.clientY * scale,
        );
    }

    /** Starts a drag session. Coordinates are parent-window client coordinates. */
    beginDrag(source: CanvasSource, startX: number, startY: number): void {
        this.#endDrag(false);
        const ghost = document.createElement('div');
        ghost.textContent = source.label;
        ghost.className =
            'pointer-events-none fixed z-[9999] hidden rounded-md bg-brand-800 px-2.5 py-1 text-xs font-semibold text-white shadow-brand-md dark:bg-gold-500 dark:text-brand-950';
        document.body.append(ghost);
        this.#drag = { source, ghost, target: null, started: false, startX, startY };

        const move = (event: PointerEvent) => this.#dragMove(event);
        const up = (event: PointerEvent) => {
            this.#dragMove(event);
            this.#endDrag(true);
        };
        const key = (event: KeyboardEvent) => {
            if (event.key === 'Escape') this.#endDrag(false);
        };
        const win = this.#iframe.contentWindow;
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        window.addEventListener('keydown', key);
        win?.addEventListener('pointermove', move);
        win?.addEventListener('pointerup', up);
        win?.addEventListener('keydown', key);
        this.#dragCleanup = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('keydown', key);
            win?.removeEventListener('pointermove', move);
            win?.removeEventListener('pointerup', up);
            win?.removeEventListener('keydown', key);
        };
    }

    #dragCleanup: (() => void) | null = null;

    #dragMove(event: PointerEvent): void {
        const drag = this.#drag;
        if (!drag) return;
        const rect = this.#iframe.getBoundingClientRect();
        const scale = rect.width / this.#iframe.offsetWidth || 1;
        // Events from inside the iframe carry iframe-space coordinates; convert to parent space.
        const fromFrame = event.view === this.#iframe.contentWindow;
        const x = fromFrame ? rect.left + event.clientX * scale : event.clientX;
        const y = fromFrame ? rect.top + event.clientY * scale : event.clientY;

        if (!drag.started) {
            if (Math.hypot(x - drag.startX, y - drag.startY) < DRAG_THRESHOLD) return;
            drag.started = true;
            drag.ghost.classList.remove('hidden');
            this.#iframe.style.pointerEvents = 'none';
            document.body.style.cursor = 'grabbing';
            this.setHover(null);
            this.#host.dragging(true);
            if (drag.source.kind === 'move') this.#find(drag.source.id)?.setAttribute('data-dragging', '');
        }
        drag.ghost.style.left = `${x + 12}px`;
        drag.ghost.style.top = `${y + 12}px`;

        const inside = x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom;
        if (!inside) {
            this.#setTarget(null);
            return;
        }
        const fx = (x - rect.left) / scale;
        const fy = (y - rect.top) / scale;
        this.#autoScroll(fy);
        this.#setTarget(this.#hitTest(fx, fy, drag.source));
    }

    #autoScroll(frameY: number): void {
        const win = this.#iframe.contentWindow;
        if (!win) return;
        const height = this.#iframe.offsetHeight;
        if (frameY < 48) win.scrollBy(0, -16);
        else if (frameY > height - 48) win.scrollBy(0, 16);
    }

    #setTarget(
        target: {
            drop: DropTarget;
            line: { left: number; top: number; width: number; height: number } | null;
            container: HTMLElement | null;
        } | null,
    ): void {
        const drag = this.#drag;
        if (!drag || !this.#doc) return;
        this.#doc.querySelectorAll('[data-drop-target]').forEach((el) => el.removeAttribute('data-drop-target'));
        if (!target) {
            drag.target = null;
            this.#indicator?.remove();
            this.#indicator = null;
            return;
        }
        drag.target = target.drop;
        target.container?.setAttribute('data-drop-target', '');
        if (!target.line) {
            this.#indicator?.remove();
            this.#indicator = null;
            return;
        }
        if (!this.#indicator) {
            this.#indicator = this.#doc.createElement('div');
            this.#indicator.className = 'sic-indicator';
            this.#doc.body.append(this.#indicator);
        }
        const { left, top, width, height } = target.line;
        Object.assign(this.#indicator.style, {
            left: `${left}px`,
            top: `${top}px`,
            width: `${width}px`,
            height: `${height}px`,
        });
    }

    #endDrag(drop: boolean): void {
        const drag = this.#drag;
        if (!drag) return;
        this.#drag = null;
        this.#dragCleanup?.();
        this.#dragCleanup = null;
        drag.ghost.remove();
        this.#iframe.style.pointerEvents = '';
        document.body.style.cursor = '';
        this.#indicator?.remove();
        this.#indicator = null;
        this.#doc?.querySelectorAll('[data-drop-target],[data-dragging]').forEach((el) => {
            el.removeAttribute('data-drop-target');
            el.removeAttribute('data-dragging');
        });
        if (drag.started) this.#host.dragging(false);
        if (drop && drag.started && drag.target) this.#host.drop(drag.source, drag.target);
        else this.render(this.#html);
    }

    /**
     * Finds where a drop at frame coordinates (x, y) lands. Walks up from the deepest block under the pointer:
     * a container accepts the drop unless the pointer is near its border (then the drop goes beside it).
     */
    #hitTest(x: number, y: number, source: CanvasSource) {
        const doc = this.#doc;
        const win = this.#iframe.contentWindow;
        const root = this.#root;
        if (!doc || !win || !root) return null;
        const moving = source.kind === 'move' ? this.#find(source.id) : null;
        for (const container of dropCandidates(doc, x, y, moving)) {
            const parentId = container?.dataset.blockId ?? null;
            if (!this.#host.canDrop(source, parentId)) continue;
            const kids = childBlocks(root, container).filter((el) => el !== moving);
            if (kids.length === 0) return { drop: { parentId, beforeId: null }, line: null, container };

            const nearest = nearestBlock(kids, x, y);
            const rect = nearest.getBoundingClientRect();
            const horizontal = container !== null && laysOutInRow(win.getComputedStyle(container), kids);
            const before = horizontal ? x < rect.left + rect.width / 2 : y < rect.top + rect.height / 2;
            const beforeEl = before ? nearest : (kids[kids.indexOf(nearest) + 1] ?? null);
            const line = indicatorLine(win, rect, horizontal, before);
            return { drop: { parentId, beforeId: beforeEl?.dataset.blockId ?? null }, line, container };
        }
        return null;
    }
}
