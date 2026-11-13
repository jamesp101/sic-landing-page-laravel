import { Columns2 } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import ColumnsPanel from '../inspector/panels/ColumnsPanel.svelte';

export const editor: EditorBlockType<'columns'> = { icon: Columns2, inspector: ColumnsPanel };
