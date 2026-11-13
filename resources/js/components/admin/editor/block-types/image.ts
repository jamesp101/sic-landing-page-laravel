import { Image } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import ImagePanel from '../inspector/panels/ImagePanel.svelte';

export const editor: EditorBlockType<'image'> = { icon: Image, inspector: ImagePanel };
