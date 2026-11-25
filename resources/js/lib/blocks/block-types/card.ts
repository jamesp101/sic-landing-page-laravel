import { z } from 'zod';
import type { BlockTypeModule } from '../block-type';
import { checkColors, colorParts, styleAttr } from '../colors';
import { esc, flow, joinClasses, paragraphsHtml } from '../render-kit';
import { body, color, label, url } from '../schema-kit';
import { richOf, textOf } from '../text-kit';
import { isExternalHref, safeHref, safeImageSrc } from '../urls';

/** The card's link (with its label typeable on the canvas); `coloured` drops the link colour for the card's own. */
function linkHtml(href: string, label: string, coloured: boolean, editable: (html: string) => string): string {
    const classes = joinClasses(
        'mt-auto text-sm font-semibold',
        !coloured && 'text-link',
        'underline-offset-4 hover:underline',
    );
    const target = isExternalHref(href) ? ' target="_blank" rel="noopener noreferrer"' : '';
    return `<a href="${esc(href)}" class="${classes}"${target}>${editable(esc(label))}<span aria-hidden="true"> →</span></a>`;
}

export const card: BlockTypeModule<'card'> = {
    meta: { label: 'Card', group: 'Content', description: 'Title, text, optional image and link.' },
    container: true,
    defaults: () => ({
        title: 'Card title',
        body: 'Short description of this card.',
        imageUrl: '',
        imageAlt: '',
        linkLabel: '',
        linkHref: '',
        color: '',
        background: '',
    }),
    schema: z.object({
        title: label,
        body,
        imageUrl: url,
        imageAlt: label,
        linkLabel: label,
        linkHref: url,
        color,
        background: color,
    }),
    rules(props, report) {
        if (props.imageUrl && !safeImageSrc(props.imageUrl)) report('This image address is not allowed.', ['imageUrl']);
        if (props.linkHref && !safeHref(props.linkHref)) report('Enter a valid link.', ['linkHref']);
        if (props.linkHref && !props.linkLabel.trim()) report('Give the card link a label.', ['linkLabel']);
        checkColors(props, ['color', 'background'], report);
    },
    html(block, ctx) {
        const p = block.props;
        const image = safeImageSrc(p.imageUrl);
        const href = p.linkLabel.trim() ? safeHref(p.linkHref) : null;
        const children = ctx.children(block.children);
        const text = colorParts(p.color, 'text');
        const bg = colorParts(p.background, 'bg');
        const parts = [
            p.title.trim()
                ? `<h3 class="${joinClasses('text-lg font-bold', !text && 'text-primary')}"${ctx.field('title', 'plain')}>${esc(p.title)}</h3>`
                : '',
            paragraphsHtml(
                p.body,
                joinClasses('text-sm leading-relaxed', !text && 'text-muted-foreground'),
                ctx.field('body', 'rich'),
            ),
            children ? flow(children, 'gap-3') : '',
            href ? linkHtml(href, p.linkLabel, text !== null, (html) => ctx.editable('linkLabel', 'plain', html)) : '',
        ].join('');
        if (!parts && !image && !ctx.editor) return '';
        const img = image
            ? `<img src="${esc(image)}" alt="${esc(p.imageAlt)}" loading="lazy" decoding="async" class="aspect-video w-full object-cover">`
            : '';
        const classes = joinClasses(
            'flex flex-col overflow-hidden rounded-2xl border border-border',
            bg ? bg.className : 'bg-surface',
            'shadow-brand-sm',
            text?.className,
        );
        return `<article class="${classes}"${styleAttr(text, bg)}${ctx.hooks(block, !parts && !image)}>${img}<div class="flex flex-1 flex-col gap-3 p-6">${parts}</div></article>`;
    },
    text: (props) => [textOf(props.title), richOf(props.body)],
};
