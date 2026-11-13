import { Table } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import TablePanel from '../inspector/panels/TablePanel.svelte';

export const editor: EditorBlockType<'table'> = { icon: Table, inspector: TablePanel };
