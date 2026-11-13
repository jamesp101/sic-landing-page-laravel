import { Hash } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import StatsPanel from '../inspector/panels/StatsPanel.svelte';

export const editor: EditorBlockType<'stats'> = { icon: Hash, inspector: StatsPanel };
