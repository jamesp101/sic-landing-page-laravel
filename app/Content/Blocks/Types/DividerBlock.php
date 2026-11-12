<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\RenderKit;
use App\Content\Blocks\Z;
use stdClass;

final class DividerBlock extends BlockType
{
    private const TONE = ['default' => 'border-border', 'strong' => 'border-border-strong', 'gold' => 'border-gold-500'];

    public function meta(): array
    {
        return ['label' => 'Divider', 'group' => 'Layout', 'description' => 'A horizontal rule.'];
    }

    public function defaults(): array
    {
        return ['tone' => 'default'];
    }

    public function schema(): array
    {
        return Z::object(['tone' => Z::enum(['default', 'strong', 'gold'], 'default')]);
    }

    public function html(array $block, RenderContext $ctx): string
    {
        return '<div class="py-1"'.$ctx->hooks($block).'><hr class="'.RenderKit::pick(self::TONE, $block['props']['tone'], 'default').'"></div>';
    }

    public function text(stdClass $props): array
    {
        return [];
    }
}
