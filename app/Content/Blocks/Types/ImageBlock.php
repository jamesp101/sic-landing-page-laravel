<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\Colors;
use App\Content\Blocks\Limits;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\RenderKit;
use App\Content\Blocks\Urls;
use App\Content\Blocks\Z;
use App\Content\Js;
use Closure;
use stdClass;

final class ImageBlock extends BlockType
{
    public function meta(): array
    {
        return ['label' => 'Image', 'group' => 'Media', 'description' => 'Uploaded or linked image with alt text, caption and link.'];
    }

    public function defaults(): array
    {
        return ['src' => '', 'alt' => '', 'decorative' => false, 'caption' => '', 'width' => null, 'height' => null, 'link' => '', 'color' => '', 'background' => ''];
    }

    public function schema(): array
    {
        return Z::object([
            'src' => Z::str(Limits::URL),
            'alt' => Z::str(Limits::LABEL),
            'decorative' => Z::bool(false),
            'caption' => Z::str(Limits::LABEL),
            'width' => Z::int(1, 20_000, null, true),
            'height' => Z::int(1, 20_000, null, true),
            'link' => Z::str(Limits::URL),
            'color' => Z::str(Limits::LABEL),
            'background' => Z::str(Limits::LABEL),
        ]);
    }

    public function rules(array $props, Closure $report): void
    {
        if ($props['src'] === '') {
            $report('Choose an image.', ['src']);
        } elseif (Urls::safeImageSrc($props['src']) === null) {
            $report('This image address is not allowed.', ['src']);
        }
        if (! $props['decorative'] && Js::blank($props['alt'])) {
            $report('Describe the image in the alt text, or mark it as decorative.', ['alt']);
        }
        if ($props['link'] !== '') {
            if (Urls::safeHref($props['link']) === null) {
                $report('Enter a valid link (https://, /path, mailto: or tel:).', ['link']);
            } elseif ($props['decorative']) {
                $report('A linked image needs alt text that names the link target.', ['link']);
            }
        }
        Colors::check($props, ['color', 'background'], $report);
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $p = $block['props'];
        $src = Urls::safeImageSrc($p['src']);
        if ($src === null) {
            return $ctx->editor
                ? '<figure class="rounded-xl border border-dashed border-border-strong bg-surface-alt p-8 text-center text-sm text-muted-foreground"'.$ctx->hooks($block).'>Choose an image in the inspector.</figure>'
                : '';
        }
        $alt = $p['decorative'] ? '' : $p['alt'];
        $size = is_int($p['width']) && is_int($p['height']) && $p['width'] > 0 && $p['height'] > 0
            ? ' width="'.$p['width'].'" height="'.$p['height'].'"'
            : '';
        $text = Colors::parts($p['color'], 'text');
        $bg = Colors::parts($p['background'], 'bg');
        $caption = ! Js::blank($p['caption'])
            ? '<figcaption class="'.RenderKit::joinClasses('mt-2 text-sm', $text !== null ? $text['className'] : 'text-muted-foreground').'"'.Colors::styleAttr($text).$ctx->field('caption', 'plain').'>'.RenderKit::esc($p['caption']).'</figcaption>'
            : '';
        $img = '<img src="'.RenderKit::esc($src).'" alt="'.RenderKit::esc($alt).'"'.$size.' loading="lazy" decoding="async" class="h-auto max-w-full rounded-xl">';
        $href = $p['link'] !== '' ? Urls::safeHref($p['link']) : null;
        $linked = match (true) {
            $href === null => $img,
            Urls::isExternalHref($href) => '<a href="'.RenderKit::esc($href).'" target="_blank" rel="noopener noreferrer" class="inline-block rounded-xl">'.$img.'<span class="sr-only"> (opens in a new tab)</span></a>',
            default => '<a href="'.RenderKit::esc($href).'" class="inline-block rounded-xl">'.$img.'</a>',
        };

        $frame = $bg !== null ? ' class="'.RenderKit::joinClasses('rounded-2xl p-4', $bg['className']).'"'.Colors::styleAttr($bg) : '';

        return '<figure'.$frame.$ctx->hooks($block).'>'.$linked.$caption.'</figure>';
    }

    public function text(stdClass $props): array
    {
        return [];
    }
}
