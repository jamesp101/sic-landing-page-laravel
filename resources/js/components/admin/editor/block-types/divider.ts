import { Minus } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import DividerPanel from '../inspector/panels/DividerPanel.svelte';

export const editor: EditorBlockType<'divider'> = { icon: Minus, inspector: DividerPanel };
