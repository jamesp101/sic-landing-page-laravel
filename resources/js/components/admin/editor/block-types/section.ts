import { RectangleHorizontal } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import SectionPanel from '../inspector/panels/SectionPanel.svelte';

export const editor: EditorBlockType<'section'> = { icon: RectangleHorizontal, inspector: SectionPanel };
