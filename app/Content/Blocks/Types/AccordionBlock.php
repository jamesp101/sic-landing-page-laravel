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
use Closure;
use stdClass;

final class AccordionBlock extends BlockType
{
    private const CHEVRON = '<svg class="h-4 w-4 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';

    public function meta(): array
    {
        return ['label' => 'Accordion / FAQ', 'group' => 'Content', 'description' => 'Expandable questions and answers.'];
    }

    public function defaults(): array
    {
        return ['items' => [['title' => 'Question', 'body' => 'Answer.']]];
    }

    public function schema(): array
    {
        return Z::object([
            'items' => Z::array(
                Z::object(['title' => Z::str(Limits::LABEL), 'body' => Z::str(Limits::TEXT)]),
                Limits::ACCORDION_ITEMS,
            ),
        ]);
    }

    public function rules(array $props, Closure $report): void
    {
        if ($props['items'] === []) {
            $report('Add at least one item.', ['items']);
        }
        foreach ($props['items'] as $item) {
            if (Js::blank($item['title'])) {
                $report('Every item needs a title.', ['items']);
                break;
            }
        }
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $items = array_filter($block['props']['items'], static fn (array $item): bool => ! Js::blank($item['title']));
        if ($items === []) {
            return '';
        }
        $html = '';
        // `array_filter` keeps the stored index: the canvas writes typed text back to `items.<index>.*`.
        foreach ($items as $index => $item) {
            $html .= '<details class="group rounded-xl border border-border bg-surface-alt p-4"><summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold text-primary [&::-webkit-details-marker]:hidden"><span'.$ctx->field("items.{$index}.title", 'plain').'>'.RenderKit::esc($item['title']).'</span>'.self::CHEVRON.'</summary><div class="mt-3 flex flex-col gap-2">'.RenderKit::paragraphsHtml($item['body'], 'text-sm leading-relaxed text-muted-foreground', $ctx->field("items.{$index}.body", 'rich')).'</div></details>';
        }

        return '<div class="flex flex-col gap-3"'.$ctx->hooks($block).'>'.$html.'</div>';
    }

    public function text(stdClass $props): array
    {
        $texts = [];
        foreach (TextKit::itemsOf($props->items ?? null) as $item) {
            if ($item instanceof stdClass) {
                $texts[] = TextKit::textOf($item->title ?? null);
                $texts[] = TextKit::richOf($item->body ?? null);
            }
        }

        return $texts;
    }
}
