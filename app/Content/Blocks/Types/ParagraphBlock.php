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
use stdClass;

final class ParagraphBlock extends BlockType
{
    private const SIZE = ['sm' => 'text-sm', 'base' => 'text-base', 'lg' => 'text-lg'];

    private const TONE = ['default' => 'text-foreground', 'muted' => 'text-muted-foreground'];

    public function meta(): array
    {
        return ['label' => 'Text', 'group' => 'Content', 'description' => 'Paragraph with bold, italic and links.'];
    }

    public function defaults(): array
    {
        return ['text' => 'Write something here.', 'size' => 'base', 'tone' => 'default', 'align' => 'inherit'];
    }

    public function schema(): array
    {
        return Z::object([
            'text' => Z::str(Limits::TEXT),
            'size' => Z::enum(['sm', 'base', 'lg'], 'base'),
            'tone' => Z::enum(['default', 'muted'], 'default'),
            'align' => Z::enum(['inherit', 'left', 'center', 'right'], 'inherit'),
        ]);
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $p = $block['props'];
        if (Js::blank($p['text'])) {
            return $ctx->editor ? '<p class="text-base text-muted-foreground"'.$ctx->hooks($block).'>&nbsp;</p>' : '';
        }
        $classes = RenderKit::joinClasses(
            'leading-relaxed',
            RenderKit::pick(self::SIZE, $p['size'], 'base'),
            RenderKit::pick(self::TONE, $p['tone'], 'default'),
            RenderKit::pick(RenderKit::TEXT_ALIGN, $p['align'], 'inherit'),
        );

        return '<p class="'.$classes.'"'.$ctx->hooks($block).'>'.Inline::inlineToHtml($p['text']).'</p>';
    }

    public function text(stdClass $props): array
    {
        return [TextKit::richOf($props->text ?? null)];
    }
}
