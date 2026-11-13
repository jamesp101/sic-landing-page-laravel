import { IdCard } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import CardPanel from '../inspector/panels/CardPanel.svelte';

export const editor: EditorBlockType<'card'> = { icon: IdCard, inspector: CardPanel };
