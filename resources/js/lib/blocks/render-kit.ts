/** HTML helpers and class tables shared by the Block type modules. Tailwind finds the class names by scanning. */
import { escapeHtml } from '../markdown';
import { inlineToHtml, paragraphsOf } from './inline';

export const esc = escapeHtml;

export const GAP = { none: 'gap-0', sm: 'gap-2', md: 'gap-4', lg: 'gap-8' } as const;
export const TEXT_ALIGN = {
    inherit: '',
    left: 'text-left',
    center: 'text-center',
    right: 'text-right',
    justify: 'text-justify',
} as const;

/** Lookup that never returns inherited/undefined entries for odd stored values. */
export function pick<T extends Record<string, string>>(table: T, key: unknown, fallback: keyof T): string {
    return typeof key === 'string' && Object.hasOwn(table, key) ? table[key] : table[fallback];
}

export function joinClasses(...parts: (string | false | null | undefined)[]): string {
    return parts.filter(Boolean).join(' ');
}

/**
 * One `<p>` per paragraph. `field` is the canvas mark of the prop (`ctx.field`, `''` publicly); each paragraph then
 * also carries its index so the canvas edits one paragraph of the prop at a time.
 */
export function paragraphsHtml(text: string, className: string, field = ''): string {
    return paragraphsOf(text)
        .map(
            (part, index) =>
                `<p class="${className}"${field ? `${field} data-edit-part="${index}"` : ''}>${inlineToHtml(part)}</p>`,
        )
        .join('');
}

export function flow(inner: string, gap = 'gap-4'): string {
    return `<div class="flex flex-col ${gap}">${inner}</div>`;
}
