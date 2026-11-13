import { MousePointerClick } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import ButtonPanel from '../inspector/panels/ButtonPanel.svelte';

export const editor: EditorBlockType<'button'> = { icon: MousePointerClick, inspector: ButtonPanel };
