import { z } from 'zod';
import type { BlockTypeModule } from '../block-type';
import { inlineToHtml } from '../inline';
import { strings } from '../schema-kit';
import { itemsOf, richOf } from '../text-kit';
import { LIMITS } from '../types';

export const list: BlockTypeModule<'list'> = {
    meta: { label: 'List', group: 'Content', description: 'Bulleted or numbered list.' },
    defaults: () => ({ ordered: false, items: ['First item', 'Second item'] }),
    schema: z.object({
        ordered: z.boolean().default(false),
        items: strings(LIMITS.listItems, z.string().max(LIMITS.label)),
    }),
    html(block, ctx) {
        const p = block.props;
        // Keep each item's stored index: the canvas writes typed text back to `items.<index>`. The canvas also shows
        // blank items (a fresh one after Enter must be typeable); the public page drops them.
        const items = p.items.flatMap((item, index) => (item.trim() || ctx.editor ? [{ item, index }] : []));
        if (items.length === 0) return '';
        const tag = p.ordered ? 'ol' : 'ul';
        const classes = p.ordered
            ? 'list-decimal space-y-1 pl-6 text-foreground marker:text-gold-600'
            : 'list-disc space-y-1 pl-6 text-foreground marker:text-gold-600';
        const lis = items
            .map(
                ({ item, index }) =>
                    `<li class="leading-relaxed"${ctx.field(`items.${index}`, 'item')}>${item.trim() ? inlineToHtml(item) : '<br>'}</li>`,
            )
            .join('');
        return `<${tag} class="${classes}"${ctx.hooks(block)}>${lis}</${tag}>`;
    },
    text: (props) => itemsOf(props.items).map(richOf),
};
