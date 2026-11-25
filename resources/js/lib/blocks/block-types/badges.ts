import { z } from 'zod';
import type { BlockTypeModule } from '../block-type';
import { esc } from '../render-kit';
import { strings } from '../schema-kit';
import { itemsOf, textOf } from '../text-kit';
import { LIMITS } from '../types';

export const badges: BlockTypeModule<'badges'> = {
    meta: { label: 'Badges', group: 'Content', description: 'A group of short labels.' },
    defaults: () => ({ items: ['Label one', 'Label two'] }),
    schema: z.object({ items: strings(LIMITS.badgeItems, z.string().max(80)) }),
    html(block, ctx) {
        // Keep each item's stored index: the canvas writes typed text back to `items.<index>`.
        const items = block.props.items.flatMap((raw, index) => {
            const item = raw.trim();
            return item ? [{ item, index }] : [];
        });
        if (items.length === 0) return '';
        const lis = items
            .map(
                ({ item, index }) =>
                    `<li class="inline-flex items-center rounded-full bg-brand-100 px-2.5 py-0.5 text-xs font-semibold text-brand-700"${ctx.field(`items.${index}`, 'plain')}>${esc(item)}</li>`,
            )
            .join('');
        return `<ul class="flex flex-wrap gap-1.5"${ctx.hooks(block)}>${lis}</ul>`;
    },
    text: (props) => [itemsOf(props.items).map(textOf).join(', ')],
};
