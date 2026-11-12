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

final class BadgesBlock extends BlockType
{
    public function meta(): array
    {
        return ['label' => 'Badges', 'group' => 'Content', 'description' => 'A group of short labels.'];
    }

    public function defaults(): array
    {
        return ['items' => ['Label one', 'Label two']];
    }

    public function schema(): array
    {
        return Z::object(['items' => Z::array(Z::string(80), Limits::BADGE_ITEMS)]);
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $items = array_filter(
            array_map(Js::trim(...), $block['props']['items']),
            static fn (string $item): bool => $item !== '',
        );
        if ($items === []) {
            return '';
        }
        $lis = '';
        foreach ($items as $item) {
            $lis .= '<li class="inline-flex items-center rounded-full bg-brand-100 px-2.5 py-0.5 text-xs font-semibold text-brand-700">'.RenderKit::esc($item).'</li>';
        }

        return '<ul class="flex flex-wrap gap-1.5"'.$ctx->hooks($block).'>'.$lis.'</ul>';
    }

    public function text(stdClass $props): array
    {
        return [implode(', ', array_map(TextKit::textOf(...), TextKit::itemsOf($props->items ?? null)))];
    }
}
