import { ChevronsUpDown } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import AccordionPanel from '../inspector/panels/AccordionPanel.svelte';

export const editor: EditorBlockType<'accordion'> = { icon: ChevronsUpDown, inspector: AccordionPanel };
