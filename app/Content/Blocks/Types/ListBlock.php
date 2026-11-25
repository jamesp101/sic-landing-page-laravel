<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\Inline;
use App\Content\Blocks\Limits;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\TextKit;
use App\Content\Blocks\Z;
use App\Content\Js;
use stdClass;

final class ListBlock extends BlockType
{
    public function meta(): array
    {
        return ['label' => 'List', 'group' => 'Content', 'description' => 'Bulleted or numbered list.'];
    }

    public function defaults(): array
    {
        return ['ordered' => false, 'items' => ['First item', 'Second item']];
    }

    public function schema(): array
    {
        return Z::object([
            'ordered' => Z::bool(false),
            'items' => Z::array(Z::string(Limits::LABEL), Limits::LIST_ITEMS),
        ]);
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $p = $block['props'];
        // The canvas also shows blank items (a fresh one after Enter must be typeable); the public page drops them.
        $items = $ctx->editor ? $p['items'] : array_filter($p['items'], static fn (string $item): bool => ! Js::blank($item));
        if ($items === []) {
            return '';
        }
        $tag = $p['ordered'] ? 'ol' : 'ul';
        $classes = $p['ordered']
            ? 'list-decimal space-y-1 pl-6 text-foreground marker:text-gold-600'
            : 'list-disc space-y-1 pl-6 text-foreground marker:text-gold-600';
        $lis = '';
        // `array_filter` keeps the stored index: the canvas writes typed text back to `items.<index>`.
        foreach ($items as $index => $item) {
            $lis .= '<li class="leading-relaxed"'.$ctx->field("items.{$index}", 'item').'>'.(Js::blank($item) ? '<br>' : Inline::inlineToHtml($item)).'</li>';
        }

        return "<{$tag} class=\"{$classes}\"".$ctx->hooks($block).'>'.$lis."</{$tag}>";
    }

    public function text(stdClass $props): array
    {
        return array_map(TextKit::richOf(...), TextKit::itemsOf($props->items ?? null));
    }
}
