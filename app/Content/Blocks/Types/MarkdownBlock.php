<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\Limits;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\TextKit;
use App\Content\Blocks\Z;
use App\Content\Markdown;
use stdClass;

final class MarkdownBlock extends BlockType
{
    public function meta(): array
    {
        return ['label' => 'Markdown (legacy)', 'group' => 'Legacy', 'description' => 'Markdown / MDX-lite source.'];
    }

    public function defaults(): array
    {
        return ['source' => ''];
    }

    public function schema(): array
    {
        return Z::object(['source' => Z::str(Limits::MARKDOWN)]);
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $html = Markdown::parseMarkdown($block['props']['source']);
        if ($html === '' && ! $ctx->editor) {
            return '';
        }

        return '<div'.$ctx->hooks($block).'>'.($html !== '' ? $html : '<p class="text-sm text-muted-foreground">Empty Markdown block.</p>').'</div>';
    }

    public function text(stdClass $props): array
    {
        return [Markdown::stripMarkdown(TextKit::textOf($props->source ?? null))];
    }
}
