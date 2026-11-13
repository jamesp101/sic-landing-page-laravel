import { FileText } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import MarkdownPanel from '../inspector/panels/MarkdownPanel.svelte';

export const editor: EditorBlockType<'markdown'> = { icon: FileText, inspector: MarkdownPanel };
