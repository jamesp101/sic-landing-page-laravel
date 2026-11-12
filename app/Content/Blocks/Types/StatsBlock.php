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

final class StatsBlock extends BlockType
{
    public function meta(): array
    {
        return ['label' => 'Stats', 'group' => 'Content', 'description' => 'Key figures with labels.'];
    }

    public function defaults(): array
    {
        return [
            'items' => [
                ['value' => '100+', 'label' => 'Label', 'hint' => ''],
                ['value' => '25', 'label' => 'Label', 'hint' => ''],
            ],
        ];
    }

    public function schema(): array
    {
        return Z::object([
            'items' => Z::array(
                Z::object(['value' => Z::str(80), 'label' => Z::str(120), 'hint' => Z::str(200)]),
                Limits::STAT_ITEMS,
            ),
        ]);
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $items = array_filter($block['props']['items'], static fn (array $item): bool => ! Js::blank($item['value']) || ! Js::blank($item['label']));
        if ($items === []) {
            return '';
        }
        $html = '';
        foreach ($items as $item) {
            $html .= '<div class="flex flex-col gap-1 rounded-2xl border border-border bg-surface p-5 text-center shadow-brand-sm"><dt class="order-2 text-sm font-medium text-muted-foreground">'.RenderKit::esc($item['label']).'</dt><dd class="order-1 text-3xl font-bold text-primary">'.RenderKit::esc($item['value']).'</dd>'.(! Js::blank($item['hint']) ? '<dd class="order-3 text-xs text-muted-foreground">'.RenderKit::esc($item['hint']).'</dd>' : '').'</div>';
        }

        return '<dl class="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-4"'.$ctx->hooks($block).'>'.$html.'</dl>';
    }

    public function text(stdClass $props): array
    {
        $texts = [];
        foreach (TextKit::itemsOf($props->items ?? null) as $item) {
            if ($item instanceof stdClass) {
                $texts[] = TextKit::textOf($item->value ?? null).' '.TextKit::textOf($item->label ?? null);
            }
        }

        return $texts;
    }
}
