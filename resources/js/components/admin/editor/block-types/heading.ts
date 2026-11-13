import { Heading } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import HeadingPanel from '../inspector/panels/HeadingPanel.svelte';

export const editor: EditorBlockType<'heading'> = { icon: Heading, inspector: HeadingPanel };
