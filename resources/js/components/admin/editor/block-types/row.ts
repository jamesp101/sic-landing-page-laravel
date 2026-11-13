import { StretchHorizontal } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import RowPanel from '../inspector/panels/RowPanel.svelte';

export const editor: EditorBlockType<'row'> = { icon: StretchHorizontal, inspector: RowPanel };
