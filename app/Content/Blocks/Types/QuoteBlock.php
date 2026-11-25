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

final class QuoteBlock extends BlockType
{
    public function meta(): array
    {
        return ['label' => 'Quote', 'group' => 'Content', 'description' => 'Highlighted quotation.'];
    }

    public function defaults(): array
    {
        return ['text' => 'A memorable line.', 'cite' => ''];
    }

    public function schema(): array
    {
        return Z::object(['text' => Z::str(Limits::TEXT), 'cite' => Z::str(Limits::LABEL)]);
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $p = $block['props'];
        if (Js::blank($p['text'])) {
            return '';
        }
        $cite = ! Js::blank($p['cite'])
            ? '<footer class="mt-2 text-sm font-semibold not-italic text-foreground">— '.$ctx->editable('cite', 'plain', RenderKit::esc($p['cite'])).'</footer>'
            : '';

        return '<blockquote class="border-l-4 border-gold-500 pl-4 italic text-muted-foreground"'.$ctx->hooks($block).'>'.RenderKit::paragraphsHtml($p['text'], 'leading-relaxed', $ctx->field('text', 'rich')).$cite.'</blockquote>';
    }

    public function text(stdClass $props): array
    {
        return [TextKit::richOf($props->text ?? null)];
    }
}
