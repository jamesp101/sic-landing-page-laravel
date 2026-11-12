<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\Inline;
use App\Content\Blocks\Limits;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\RenderKit;
use App\Content\Blocks\TextKit;
use App\Content\Blocks\Z;
use App\Content\Js;
use Closure;
use stdClass;

final class TableBlock extends BlockType
{
    public function meta(): array
    {
        return ['label' => 'Table', 'group' => 'Content', 'description' => 'Editable rows and columns.'];
    }

    public function defaults(): array
    {
        return ['header' => true, 'caption' => '', 'rows' => [['Column A', 'Column B'], ['Value', 'Value']]];
    }

    public function schema(): array
    {
        return Z::object([
            'header' => Z::bool(true),
            'caption' => Z::str(Limits::LABEL),
            'rows' => Z::array(
                Z::array(Z::string(Limits::CELL), Limits::TABLE_COLUMNS, 1),
                Limits::TABLE_ROWS,
            ),
        ]);
    }

    public function rules(array $props, Closure $report): void
    {
        if ($props['rows'] === []) {
            $report('Add at least one row.', ['rows']);
        }
        $width = count($props['rows'][0] ?? []);
        foreach ($props['rows'] as $row) {
            if (count($row) !== $width) {
                $report('Every row needs the same number of columns.', ['rows']);
                break;
            }
        }
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $p = $block['props'];
        $rows = array_values(array_filter($p['rows'], static fn (array $row): bool => $row !== []));
        if ($rows === []) {
            return '';
        }
        $th = static fn (string $cell): string => '<th scope="col" class="border-b-2 border-border-strong bg-surface-alt px-4 py-2 text-left font-semibold text-primary">'.Inline::inlineToHtml($cell).'</th>';
        $td = static fn (string $cell): string => '<td class="border-b border-border px-4 py-2 align-top text-foreground">'.Inline::inlineToHtml($cell).'</td>';
        $tr = static fn (array $row, callable $cell): string => '<tr>'.implode('', array_map($cell, $row)).'</tr>';
        $head = $p['header'] ? '<thead>'.$tr($rows[0], $th).'</thead>' : '';
        $bodyRows = $p['header'] ? array_slice($rows, 1) : $rows;
        $caption = ! Js::blank($p['caption'])
            ? '<caption class="caption-top pb-2 text-left text-sm font-semibold text-muted-foreground">'.RenderKit::esc($p['caption']).'</caption>'
            : '';
        $body = implode('', array_map(static fn (array $row): string => $tr($row, $td), $bodyRows));

        return '<div class="overflow-x-auto"'.$ctx->hooks($block).'><table class="w-full border-collapse text-left text-sm">'.$caption.$head.'<tbody>'.$body.'</tbody></table></div>';
    }

    public function text(stdClass $props): array
    {
        return array_map(
            static fn (mixed $row): string => implode(' ', array_map(TextKit::richOf(...), TextKit::itemsOf($row))),
            TextKit::itemsOf($props->rows ?? null),
        );
    }
}
