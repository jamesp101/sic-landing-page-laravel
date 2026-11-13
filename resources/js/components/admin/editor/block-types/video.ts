import { Video } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import VideoPanel from '../inspector/panels/VideoPanel.svelte';

export const editor: EditorBlockType<'video'> = { icon: Video, inspector: VideoPanel };
