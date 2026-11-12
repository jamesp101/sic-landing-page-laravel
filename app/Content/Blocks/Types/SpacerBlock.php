<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\RenderKit;
use App\Content\Blocks\Z;
use stdClass;

final class SpacerBlock extends BlockType
{
    private const SIZE = ['sm' => 'h-2', 'md' => 'h-6', 'lg' => 'h-12', 'xl' => 'h-20'];

    public function meta(): array
    {
        return ['label' => 'Spacer', 'group' => 'Layout', 'description' => 'Vertical whitespace.'];
    }

    public function defaults(): array
    {
        return ['size' => 'md'];
    }

    public function schema(): array
    {
        return Z::object(['size' => Z::enum(['sm', 'md', 'lg', 'xl'], 'md')]);
    }

    public function html(array $block, RenderContext $ctx): string
    {
        return '<div class="'.RenderKit::pick(self::SIZE, $block['props']['size'], 'md').'" aria-hidden="true"'.$ctx->hooks($block).'></div>';
    }

    public function text(stdClass $props): array
    {
        return [];
    }
}
