import { Images } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import AlbumPanel from '../inspector/panels/AlbumPanel.svelte';

export const editor: EditorBlockType<'album'> = { icon: Images, inspector: AlbumPanel };
