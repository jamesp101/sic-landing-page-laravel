import { z } from 'zod';
import type { BlockTypeModule } from '../block-type';
import { inlineToHtml } from '../inline';
import { esc } from '../render-kit';
import { label } from '../schema-kit';
import { itemsOf, richOf } from '../text-kit';
import { LIMITS } from '../types';

export const table: BlockTypeModule<'table'> = {
    meta: { label: 'Table', group: 'Content', description: 'Editable rows and columns.' },
    defaults: () => ({
        header: true,
        caption: '',
        rows: [
            ['Column A', 'Column B'],
            ['Value', 'Value'],
        ],
    }),
    schema: z.object({
        header: z.boolean().default(true),
        caption: label,
        rows: z
            .array(z.array(z.string().max(LIMITS.cell)).min(1).max(LIMITS.tableColumns))
            .max(LIMITS.tableRows)
            .default([]),
    }),
    rules(props, report) {
        if (props.rows.length === 0) report('Add at least one row.', ['rows']);
        const width = props.rows[0]?.length ?? 0;
        if (props.rows.some((row) => row.length !== width))
            report('Every row needs the same number of columns.', ['rows']);
    },
    html(block, ctx) {
        const p = block.props;
        // Keep each row's stored index: the canvas writes typed text back to `rows.<row>.<column>`.
        const rows = p.rows.flatMap((cells, index) => (cells.length > 0 ? [{ cells, index }] : []));
        if (rows.length === 0) return '';
        type Row = (typeof rows)[number];
        const th = (cell: string, field: string) =>
            `<th scope="col" class="border-b-2 border-border-strong bg-surface-alt px-4 py-2 text-left font-semibold text-primary"${field}>${inlineToHtml(cell)}</th>`;
        const td = (cell: string, field: string) =>
            `<td class="border-b border-border px-4 py-2 align-top text-foreground"${field}>${inlineToHtml(cell)}</td>`;
        const tr = (row: Row, cell: (value: string, field: string) => string) =>
            `<tr>${row.cells.map((value, column) => cell(value, ctx.field(`rows.${row.index}.${column}`, 'rich'))).join('')}</tr>`;
        const head = p.header ? `<thead>${tr(rows[0], th)}</thead>` : '';
        const bodyRows = p.header ? rows.slice(1) : rows;
        const caption = p.caption.trim()
            ? `<caption class="caption-top pb-2 text-left text-sm font-semibold text-muted-foreground"${ctx.field('caption', 'plain')}>${esc(p.caption)}</caption>`
            : '';
        return `<div class="overflow-x-auto"${ctx.hooks(block)}><table class="w-full border-collapse text-left text-sm">${caption}${head}<tbody>${bodyRows.map((row) => tr(row, td)).join('')}</tbody></table></div>`;
    },
    text: (props) => itemsOf(props.rows).map((row) => itemsOf(row).map(richOf).join(' ')),
};
