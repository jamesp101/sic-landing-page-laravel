/**
 * The contract of one Block type. Every Block type is a module in `block-types/` that implements
 * `BlockTypeModule` and is listed once in `registry.ts`, whose type makes the compiler reject a Block type
 * without a module. Pure TypeScript: no Svelte, no DOM (the editor's icon and Inspector panel live in the
 * editor module of the same name in `components/admin/editor/block-types/`).
 */
import type { z } from 'zod';
import type { Block, BlockMeta, BlockType, PropsByType } from './types';

/** Reports a cross-field problem on a prop (`path` = the prop's path, e.g. `['images', 1, 'alt']`). */
export type Report = (message: string, path?: (string | number)[]) => void;

/** What a Block type's `html` needs from the renderer. */
export interface RenderContext {
    /** Admin canvas: adds editor hooks and placeholders. */
    editor: boolean;
    /** The `data-block-*` attributes of the canvas (leading space included), `''` on the public site. */
    hooks(block: Block, empty?: boolean): string;
    /** Renders child blocks in this context. */
    children(children: Block[] | undefined): string;
    /**
     * Marks the element holding a text prop as typeable on the canvas (leading space included), `''` on the public
     * site. `path` is the prop's path (`'title'`, `'items.2.body'`); `rich` keeps bold, italic, links and line breaks;
     * `item` is rich text that is one entry of a string list (`items.<index>`): Enter and pasted lines make new entries.
     */
    field(path: string, kind: EditKind): string;
    /** Wraps escaped text that shares its element with other markup in a marked `<span>`; the text itself publicly. */
    editable(path: string, kind: EditKind, html: string): string;
}

export type EditKind = 'plain' | 'rich' | 'item';

/** Stored JSON of unknown shape, as seen by plain-text extraction. */
export type Json = Record<string, unknown>;

export interface BlockTypeModule<T extends BlockType> {
    /** Palette label, group and description. */
    meta: BlockMeta;
    /** Holds child blocks. */
    container?: true;
    /** Can only be placed at the top level of a document. */
    rootOnly?: true;
    /** Props of a freshly created block. */
    defaults(): PropsByType[T];
    /** Shape of the props: whitelisted values, limits, defaults for missing props. */
    schema: z.ZodType;
    /** Cross-field rules that need more than a shape check; run on schema-normalised props. */
    rules?(props: PropsByType[T], report: Report): void;
    /** Public HTML (or the canvas HTML when `ctx.editor`); every string escaped, every URL filtered. */
    html(block: Block<T>, ctx: RenderContext): string;
    /** Readable texts for search and excerpts, from stored props of unknown shape. Tolerates anything. */
    text(props: Json): string[];
}
