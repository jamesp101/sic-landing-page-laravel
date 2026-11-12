<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\Limits;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\RenderKit;
use App\Content\Blocks\TextKit;
use App\Content\Blocks\Z;
use App\Content\Js;
use stdClass;

final class HeadingBlock extends BlockType
{
    private const LEVEL = [
        2 => 'text-2xl font-bold text-primary',
        3 => 'text-xl font-bold text-primary',
        4 => 'text-lg font-semibold text-primary',
    ];

    public function meta(): array
    {
        return ['label' => 'Heading', 'group' => 'Content', 'description' => 'Section heading (h2 to h4).'];
    }

    public function defaults(): array
    {
        return ['text' => 'Heading', 'level' => 2, 'align' => 'inherit'];
    }

    public function schema(): array
    {
        return Z::object([
            'text' => Z::str(Limits::LABEL),
            'level' => Z::literals([2, 3, 4], 2, 'Headings can be level 2, 3 or 4.'),
            'align' => Z::enum(['inherit', 'left', 'center', 'right'], 'inherit'),
        ]);
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $p = $block['props'];
        if (Js::blank($p['text'])) {
            return '';
        }
        // Never h1 (the page owns it) and never below h4 in this document model.
        $level = $p['level'] === 3 || $p['level'] === 4 ? $p['level'] : 2;
        $classes = RenderKit::joinClasses(self::LEVEL[$level], RenderKit::pick(RenderKit::TEXT_ALIGN, $p['align'], 'inherit'));

        return "<h{$level} class=\"{$classes}\"".$ctx->hooks($block).'>'.RenderKit::esc($p['text'])."</h{$level}>";
    }

    public function text(stdClass $props): array
    {
        return [TextKit::textOf($props->text ?? null)];
    }
}
