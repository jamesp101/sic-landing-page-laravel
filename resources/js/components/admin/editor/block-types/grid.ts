import { LayoutGrid } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import GridPanel from '../inspector/panels/GridPanel.svelte';

export const editor: EditorBlockType<'grid'> = { icon: LayoutGrid, inspector: GridPanel };
