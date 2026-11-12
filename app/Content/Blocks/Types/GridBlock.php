<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\RenderKit;
use App\Content\Blocks\Z;
use stdClass;

final class GridBlock extends BlockType
{
    private const COLUMNS = [
        1 => 'grid-cols-1',
        2 => 'grid-cols-1 sm:grid-cols-2',
        3 => 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
        4 => 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4',
        5 => 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-5',
        6 => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6',
    ];

    public function meta(): array
    {
        return ['label' => 'Grid', 'group' => 'Layout', 'description' => 'Responsive grid with 1 to 6 columns.'];
    }

    public function container(): bool
    {
        return true;
    }

    public function defaults(): array
    {
        return ['columns' => 3, 'gap' => 'md'];
    }

    public function schema(): array
    {
        return Z::object([
            'columns' => Z::int(1, 6, 3),
            'gap' => Z::enum(['none', 'sm', 'md', 'lg'], 'md'),
        ]);
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $p = $block['props'];
        $inner = $ctx->children($block['children'] ?? null);
        if ($inner === '' && ! $ctx->editor) {
            return '';
        }
        $classes = RenderKit::joinClasses(
            'grid [&>*]:min-w-0',
            RenderKit::pick(self::COLUMNS, (string) $p['columns'], '3'),
            RenderKit::pick(RenderKit::GAP, $p['gap'], 'md'),
        );

        return '<div class="'.$classes.'"'.$ctx->hooks($block, $inner === '').'>'.$inner.'</div>';
    }

    public function text(stdClass $props): array
    {
        return [];
    }
}
