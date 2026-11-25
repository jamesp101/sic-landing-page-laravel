<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\Colors;
use App\Content\Blocks\Limits;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\RenderKit;
use App\Content\Blocks\TextKit;
use App\Content\Blocks\Urls;
use App\Content\Blocks\Z;
use App\Content\Js;
use Closure;
use stdClass;

final class CardBlock extends BlockType
{
    public function meta(): array
    {
        return ['label' => 'Card', 'group' => 'Content', 'description' => 'Title, text, optional image and link.'];
    }

    public function container(): bool
    {
        return true;
    }

    public function defaults(): array
    {
        return [
            'title' => 'Card title',
            'body' => 'Short description of this card.',
            'imageUrl' => '',
            'imageAlt' => '',
            'linkLabel' => '',
            'linkHref' => '',
            'color' => '',
            'background' => '',
        ];
    }

    public function schema(): array
    {
        return Z::object([
            'title' => Z::str(Limits::LABEL),
            'body' => Z::str(Limits::TEXT),
            'imageUrl' => Z::str(Limits::URL),
            'imageAlt' => Z::str(Limits::LABEL),
            'linkLabel' => Z::str(Limits::LABEL),
            'linkHref' => Z::str(Limits::URL),
            'color' => Z::str(Limits::LABEL),
            'background' => Z::str(Limits::LABEL),
        ]);
    }

    public function rules(array $props, Closure $report): void
    {
        if ($props['imageUrl'] !== '' && Urls::safeImageSrc($props['imageUrl']) === null) {
            $report('This image address is not allowed.', ['imageUrl']);
        }
        if ($props['linkHref'] !== '' && Urls::safeHref($props['linkHref']) === null) {
            $report('Enter a valid link.', ['linkHref']);
        }
        if ($props['linkHref'] !== '' && Js::blank($props['linkLabel'])) {
            $report('Give the card link a label.', ['linkLabel']);
        }
        Colors::check($props, ['color', 'background'], $report);
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $p = $block['props'];
        $image = Urls::safeImageSrc($p['imageUrl']);
        $href = ! Js::blank($p['linkLabel']) ? Urls::safeHref($p['linkHref']) : null;
        $children = $ctx->children($block['children'] ?? null);
        $text = Colors::parts($p['color'], 'text');
        $bg = Colors::parts($p['background'], 'bg');
        $parts = implode('', [
            ! Js::blank($p['title'])
                ? '<h3 class="'.RenderKit::joinClasses('text-lg font-bold', $text === null ? 'text-primary' : null).'"'.$ctx->field('title', 'plain').'>'.RenderKit::esc($p['title']).'</h3>'
                : '',
            RenderKit::paragraphsHtml($p['body'], RenderKit::joinClasses('text-sm leading-relaxed', $text === null ? 'text-muted-foreground' : null), $ctx->field('body', 'rich')),
            $children !== '' ? RenderKit::flow($children, 'gap-3') : '',
            $href !== null
                ? '<a href="'.RenderKit::esc($href).'" class="'.RenderKit::joinClasses('mt-auto text-sm font-semibold', $text === null ? 'text-link' : null, 'underline-offset-4 hover:underline').'"'.(Urls::isExternalHref($href) ? ' target="_blank" rel="noopener noreferrer"' : '').'>'.$ctx->editable('linkLabel', 'plain', RenderKit::esc($p['linkLabel'])).'<span aria-hidden="true"> →</span></a>'
                : '',
        ]);
        if ($parts === '' && $image === null && ! $ctx->editor) {
            return '';
        }
        $img = $image !== null
            ? '<img src="'.RenderKit::esc($image).'" alt="'.RenderKit::esc($p['imageAlt']).'" loading="lazy" decoding="async" class="aspect-video w-full object-cover">'
            : '';

        $classes = RenderKit::joinClasses(
            'flex flex-col overflow-hidden rounded-2xl border border-border',
            $bg !== null ? $bg['className'] : 'bg-surface',
            'shadow-brand-sm',
            $text['className'] ?? null,
        );

        return '<article class="'.$classes.'"'.Colors::styleAttr($text, $bg).$ctx->hooks($block, $parts === '' && $image === null).'>'.$img.'<div class="flex flex-1 flex-col gap-3 p-6">'.$parts.'</div></article>';
    }

    public function text(stdClass $props): array
    {
        return [TextKit::textOf($props->title ?? null), TextKit::richOf($props->body ?? null)];
    }
}
