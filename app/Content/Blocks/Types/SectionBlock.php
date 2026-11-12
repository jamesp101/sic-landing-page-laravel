<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\RenderKit;
use App\Content\Blocks\Z;
use stdClass;

final class SectionBlock extends BlockType
{
    private const BACKGROUND = [
        'none' => '',
        'surface' => 'border border-border bg-surface',
        'alt' => 'bg-surface-alt',
        'accent' => 'bg-accent',
        'gold' => 'border border-gold-500/30 bg-gold-500/10',
    ];

    private const PADDING = ['none' => '', 'sm' => 'p-4', 'md' => 'p-6 md:p-8', 'lg' => 'p-8 md:p-12'];

    public function meta(): array
    {
        return ['label' => 'Section', 'group' => 'Layout', 'description' => 'Background and padding around other elements.'];
    }

    public function container(): bool
    {
        return true;
    }

    public function rootOnly(): bool
    {
        return true;
    }

    public function defaults(): array
    {
        return ['background' => 'alt', 'padding' => 'md', 'align' => 'inherit'];
    }

    public function schema(): array
    {
        return Z::object([
            'background' => Z::enum(['none', 'surface', 'alt', 'accent', 'gold'], 'alt'),
            'padding' => Z::enum(['none', 'sm', 'md', 'lg'], 'md'),
            'align' => Z::enum(['inherit', 'left', 'center', 'right'], 'inherit'),
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
            'rounded-2xl',
            RenderKit::pick(self::BACKGROUND, $p['background'], 'alt'),
            RenderKit::pick(self::PADDING, $p['padding'], 'md'),
            RenderKit::pick(RenderKit::TEXT_ALIGN, $p['align'], 'inherit'),
        );

        return '<section class="'.$classes.'"'.$ctx->hooks($block, $inner === '').'>'.RenderKit::flow($inner, 'gap-5').'</section>';
    }

    public function text(stdClass $props): array
    {
        return [];
    }
}
