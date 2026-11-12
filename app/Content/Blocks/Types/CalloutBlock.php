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

final class CalloutBlock extends BlockType
{
    private const TONE = [
        'info' => ['box' => 'border-status-event-fg/30 bg-status-event-bg text-status-event-fg', 'label' => 'Note'],
        'success' => ['box' => 'border-status-article-fg/30 bg-status-article-bg text-status-article-fg', 'label' => 'Success'],
        'warning' => ['box' => 'border-gold-500/40 bg-gold-500/10 text-foreground', 'label' => 'Warning'],
    ];

    public function meta(): array
    {
        return ['label' => 'Callout', 'group' => 'Content', 'description' => 'Info, success or warning notice.'];
    }

    public function defaults(): array
    {
        return ['tone' => 'info', 'title' => 'Good to know', 'text' => 'Important information goes here.'];
    }

    public function schema(): array
    {
        return Z::object([
            'tone' => Z::enum(['info', 'success', 'warning'], 'info'),
            'title' => Z::str(Limits::LABEL),
            'text' => Z::str(Limits::TEXT),
        ]);
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $p = $block['props'];
        if (Js::blank($p['title']) && Js::blank($p['text'])) {
            return '';
        }
        $tone = self::TONE[$p['tone']] ?? self::TONE['info'];
        $title = ! Js::blank($p['title'])
            ? '<p class="font-bold"><span class="sr-only">'.$tone['label'].': </span>'.RenderKit::esc($p['title']).'</p>'
            : '<p class="sr-only">'.$tone['label'].'</p>';

        return '<div role="note" class="flex flex-col gap-1 rounded-xl border p-4 '.$tone['box'].'"'.$ctx->hooks($block).'>'.$title.RenderKit::paragraphsHtml($p['text'], 'text-sm leading-relaxed').'</div>';
    }

    public function text(stdClass $props): array
    {
        return [TextKit::textOf($props->title ?? null), TextKit::richOf($props->text ?? null)];
    }
}
