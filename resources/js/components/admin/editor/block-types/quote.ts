import { Quote } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import QuotePanel from '../inspector/panels/QuotePanel.svelte';

export const editor: EditorBlockType<'quote'> = { icon: Quote, inspector: QuotePanel };
