<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\RenderKit;
use App\Content\Blocks\Z;
use stdClass;

final class RowBlock extends BlockType
{
    private const DIRECTION = [
        'row' => 'flex-row',
        'column' => 'flex-col',
        'responsive' => 'flex-col md:flex-row md:[&>*]:flex-1',
    ];

    private const ALIGN = ['start' => 'items-start', 'center' => 'items-center', 'end' => 'items-end', 'stretch' => 'items-stretch'];

    private const JUSTIFY = ['start' => 'justify-start', 'center' => 'justify-center', 'end' => 'justify-end', 'between' => 'justify-between'];

    public function meta(): array
    {
        return ['label' => 'Row / Flex', 'group' => 'Layout', 'description' => 'Flexible row or column with gap and alignment.'];
    }

    public function container(): bool
    {
        return true;
    }

    public function defaults(): array
    {
        return ['direction' => 'responsive', 'gap' => 'md', 'align' => 'stretch', 'justify' => 'start', 'wrap' => false];
    }

    public function schema(): array
    {
        return Z::object([
            'direction' => Z::enum(['row', 'column', 'responsive'], 'responsive'),
            'gap' => Z::enum(['none', 'sm', 'md', 'lg'], 'md'),
            'align' => Z::enum(['start', 'center', 'end', 'stretch'], 'stretch'),
            'justify' => Z::enum(['start', 'center', 'end', 'between'], 'start'),
            'wrap' => Z::bool(false),
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
            'flex [&>*]:min-w-0',
            RenderKit::pick(self::DIRECTION, $p['direction'], 'responsive'),
            RenderKit::pick(RenderKit::GAP, $p['gap'], 'md'),
            RenderKit::pick(self::ALIGN, $p['align'], 'stretch'),
            RenderKit::pick(self::JUSTIFY, $p['justify'], 'start'),
            $p['wrap'] === true ? 'flex-wrap' : false,
        );

        return '<div class="'.$classes.'"'.$ctx->hooks($block, $inner === '').'>'.$inner.'</div>';
    }

    public function text(stdClass $props): array
    {
        return [];
    }
}
