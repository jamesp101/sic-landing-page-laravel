<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\RenderKit;
use App\Content\Blocks\Z;
use stdClass;

final class ColumnsBlock extends BlockType
{
    private const TEMPLATE = [
        '2-equal' => 'md:grid-cols-2',
        '2-wide-left' => 'md:grid-cols-[2fr_1fr]',
        '2-wide-right' => 'md:grid-cols-[1fr_2fr]',
        '3-equal' => 'md:grid-cols-3',
        '3-wide-left' => 'md:grid-cols-3',
        '3-wide-right' => 'md:grid-cols-3',
    ];

    private const RATIO = ['equal' => 'equal', 'wide-left' => 'wide-left', 'wide-right' => 'wide-right'];

    public function meta(): array
    {
        return ['label' => 'Columns', 'group' => 'Layout', 'description' => 'Two or three side-by-side columns.'];
    }

    public function container(): bool
    {
        return true;
    }

    public function defaults(): array
    {
        return ['count' => 2, 'ratio' => 'equal', 'gap' => 'lg'];
    }

    public function schema(): array
    {
        return Z::object([
            'count' => Z::literals([2, 3], 2),
            'ratio' => Z::enum(['equal', 'wide-left', 'wide-right'], 'equal'),
            'gap' => Z::enum(['none', 'sm', 'md', 'lg'], 'lg'),
        ]);
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $p = $block['props'];
        $inner = $ctx->children($block['children'] ?? null);
        if ($inner === '' && ! $ctx->editor) {
            return '';
        }
        $count = $p['count'] === 3 ? 3 : 2;
        $key = $count.'-'.RenderKit::pick(self::RATIO, $p['ratio'], 'equal');
        $classes = RenderKit::joinClasses(
            'grid grid-cols-1 [&>*]:min-w-0',
            RenderKit::pick(self::TEMPLATE, $key, '2-equal'),
            RenderKit::pick(RenderKit::GAP, $p['gap'], 'lg'),
        );

        return '<div class="'.$classes.'"'.$ctx->hooks($block, $inner === '').'>'.$inner.'</div>';
    }

    public function text(stdClass $props): array
    {
        return [];
    }
}
