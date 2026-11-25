import { z } from 'zod';
import type { BlockTypeModule } from '../block-type';
import { checkColors, colorParts, styleAttr } from '../colors';
import { esc, joinClasses, pick, TEXT_ALIGN } from '../render-kit';
import { color, label, textAlign } from '../schema-kit';
import { textOf } from '../text-kit';

const LEVEL = {
    2: 'text-2xl font-bold',
    3: 'text-xl font-bold',
    4: 'text-lg font-semibold',
} as const;

export const heading: BlockTypeModule<'heading'> = {
    meta: { label: 'Heading', group: 'Content', description: 'Section heading (h2 to h4).' },
    defaults: () => ({ text: 'Heading', level: 2, align: 'inherit', color: '' }),
    schema: z.object({
        text: label,
        level: z
            .union([z.literal(2), z.literal(3), z.literal(4)], { error: 'Headings can be level 2, 3 or 4.' })
            .default(2),
        align: textAlign,
        color,
    }),
    rules(props, report) {
        checkColors(props, ['color'], report);
    },
    html(block, ctx) {
        const p = block.props;
        if (!p.text.trim()) return '';
        // Never h1 (the page owns it) and never below h4 in this document model.
        const level = p.level === 3 || p.level === 4 ? p.level : 2;
        const text = colorParts(p.color, 'text');
        const classes = joinClasses(
            LEVEL[level],
            text ? text.className : 'text-primary',
            pick(TEXT_ALIGN, p.align, 'inherit'),
        );
        return `<h${level} class="${classes}"${styleAttr(text)}${ctx.hooks(block)}${ctx.field('text', 'plain')}>${esc(p.text)}</h${level}>`;
    },
    text: (props) => [textOf(props.text)],
};
