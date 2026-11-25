import { z } from 'zod';
import type { BlockTypeModule } from '../block-type';
import { checkColors, colorParts, styleAttr, type ColorParts } from '../colors';
import { esc, joinClasses } from '../render-kit';
import { color, label, url } from '../schema-kit';
import { isExternalHref, safeHref, safeImageSrc } from '../urls';

/** `width` / `height` attributes when both sizes are known (they reserve the space before the image loads). */
function sizeAttrs(width: number | null, height: number | null): string {
    if (width === null || height === null || !Number.isInteger(width) || !Number.isInteger(height)) return '';
    return width > 0 && height > 0 ? ` width="${width}" height="${height}"` : '';
}

/** The image inside its link, if it has a valid one; external links open in a new tab. */
function linkedImage(img: string, link: string): string {
    const href = link ? safeHref(link) : null;
    if (href === null) return img;
    if (isExternalHref(href)) {
        return `<a href="${esc(href)}" target="_blank" rel="noopener noreferrer" class="inline-block rounded-xl">${img}<span class="sr-only"> (opens in a new tab)</span></a>`;
    }
    return `<a href="${esc(href)}" class="inline-block rounded-xl">${img}</a>`;
}

function captionHtml(caption: string, text: ColorParts | null, field: string): string {
    if (!caption.trim()) return '';
    const classes = joinClasses('mt-2 text-sm', text ? text.className : 'text-muted-foreground');
    return `<figcaption class="${classes}"${styleAttr(text)}${field}>${esc(caption)}</figcaption>`;
}

export const image: BlockTypeModule<'image'> = {
    meta: {
        label: 'Image',
        group: 'Media',
        description: 'Uploaded or linked image with alt text, caption and link.',
    },
    defaults: () => ({
        src: '',
        alt: '',
        decorative: false,
        caption: '',
        width: null,
        height: null,
        link: '',
        color: '',
        background: '',
    }),
    schema: z.object({
        src: url,
        alt: label,
        decorative: z.boolean().default(false),
        caption: label,
        width: z.number().int().min(1).max(20_000).nullable().default(null),
        height: z.number().int().min(1).max(20_000).nullable().default(null),
        link: url,
        color,
        background: color,
    }),
    rules(props, report) {
        if (!props.src) report('Choose an image.', ['src']);
        else if (!safeImageSrc(props.src)) report('This image address is not allowed.', ['src']);
        if (!props.decorative && !props.alt.trim()) {
            report('Describe the image in the alt text, or mark it as decorative.', ['alt']);
        }
        if (props.link) {
            if (!safeHref(props.link)) report('Enter a valid link (https://, /path, mailto: or tel:).', ['link']);
            else if (props.decorative) report('A linked image needs alt text that names the link target.', ['link']);
        }
        checkColors(props, ['color', 'background'], report);
    },
    html(block, ctx) {
        const p = block.props;
        const src = safeImageSrc(p.src);
        if (src === null)
            return ctx.editor
                ? `<figure class="rounded-xl border border-dashed border-border-strong bg-surface-alt p-8 text-center text-sm text-muted-foreground"${ctx.hooks(block)}>Choose an image in the inspector.</figure>`
                : '';
        const alt = p.decorative ? '' : p.alt;
        const caption = captionHtml(p.caption, colorParts(p.color, 'text'), ctx.field('caption', 'plain'));
        const img = `<img src="${esc(src)}" alt="${esc(alt)}"${sizeAttrs(p.width, p.height)} loading="lazy" decoding="async" class="h-auto max-w-full rounded-xl">`;
        const bg = colorParts(p.background, 'bg');
        const frame = bg ? ` class="${joinClasses('rounded-2xl p-4', bg.className)}"${styleAttr(bg)}` : '';
        return `<figure${frame}${ctx.hooks(block)}>${linkedImage(img, p.link)}${caption}</figure>`;
    },
    text: () => [],
};
