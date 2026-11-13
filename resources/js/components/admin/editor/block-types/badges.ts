import { Tags } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import BadgesPanel from '../inspector/panels/BadgesPanel.svelte';

export const editor: EditorBlockType<'badges'> = { icon: Tags, inspector: BadgesPanel };
