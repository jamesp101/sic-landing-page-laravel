/**
 * Client side of the Block type registry: the palette icon and Inspector panel of every Block type, one module
 * per type in `block-types/` (named after the type, exporting `editor`). Kept apart from `lib/blocks` so that
 * stays free of Svelte. A type without a module (or a module without a type) throws when this file loads, so
 * the editor, `pnpm run check`'s tests and the editor e2e fail instead of rendering a missing icon.
 */
import type { Component } from 'svelte';
import type { Info } from '@lucide/svelte';
import { BLOCK_TYPES } from '@/lib/blocks/registry';
import type { BlockType } from '@/lib/blocks/types';
import type { PanelProps } from './inspector/panel';

export interface EditorBlockType<T extends BlockType> {
    icon: typeof Info;
    inspector: Component<PanelProps<T>>;
}

const modules = import.meta.glob<{ editor: EditorBlockType<BlockType> }>('./block-types/*.ts', { eager: true });

export const EDITOR_BLOCKS = Object.fromEntries(
    Object.entries(modules).map(([path, module]) => [
        path.slice('./block-types/'.length, -'.ts'.length),
        module.editor,
    ]),
) as { [T in BlockType]: EditorBlockType<T> };

for (const type of BLOCK_TYPES) {
    if (!(type in EDITOR_BLOCKS))
        throw new Error(`Block type "${type}" has no editor module in block-types/${type}.ts`);
}
for (const type of Object.keys(EDITOR_BLOCKS)) {
    if (!BLOCK_TYPES.includes(type as BlockType)) throw new Error(`block-types/${type}.ts is not a Block type`);
}

/** The Inspector panel of a Block type known only at runtime: its block and props are the union over all types. */
export function inspectorPanel(type: BlockType): Component<PanelProps<BlockType>> {
    return EDITOR_BLOCKS[type].inspector as unknown as Component<PanelProps<BlockType>>;
}
