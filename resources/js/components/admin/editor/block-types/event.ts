import { CalendarDays } from '@lucide/svelte';
import type { EditorBlockType } from '../block-editors';
import EventPanel from '../inspector/panels/EventPanel.svelte';

export const editor: EditorBlockType<'event'> = { icon: CalendarDays, inspector: EventPanel };
