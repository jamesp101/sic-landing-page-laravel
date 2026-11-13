import { MoveVertical } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import SpacerPanel from '../inspector/panels/SpacerPanel.svelte';

export const editor: EditorBlockType<'spacer'> = { icon: MoveVertical, inspector: SpacerPanel };
