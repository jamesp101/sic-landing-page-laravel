import { Type } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import ParagraphPanel from '../inspector/panels/ParagraphPanel.svelte';

export const editor: EditorBlockType<'paragraph'> = { icon: Type, inspector: ParagraphPanel };
