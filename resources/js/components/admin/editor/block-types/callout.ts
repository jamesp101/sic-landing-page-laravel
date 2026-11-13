import { Info } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import CalloutPanel from '../inspector/panels/CalloutPanel.svelte';

export const editor: EditorBlockType<'callout'> = { icon: Info, inspector: CalloutPanel };
