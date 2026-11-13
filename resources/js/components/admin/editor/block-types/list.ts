import { List } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import ListPanel from '../inspector/panels/ListPanel.svelte';

export const editor: EditorBlockType<'list'> = { icon: List, inspector: ListPanel };
