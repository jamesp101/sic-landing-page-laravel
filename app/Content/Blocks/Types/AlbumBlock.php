<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\Limits;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\RenderKit;
use App\Content\Blocks\TextKit;
use App\Content\Blocks\Urls;
use App\Content\Blocks\Z;
use App\Content\Js;
use Closure;
use stdClass;

final class AlbumBlock extends BlockType
{
    /** Hooks the public lightbox script (`resources/js/scripts/lightbox.ts`) binds to; see `Content::hasAlbum()`. */
    public const ALBUM_ATTR = 'data-album';

    public const ALBUM_ITEM_ATTR = 'data-album-item';

    private const COLUMNS = [
        2 => 'grid-cols-2',
        3 => 'grid-cols-2 sm:grid-cols-3',
        4 => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4',
    ];

    public function meta(): array
    {
        return ['label' => 'Album', 'group' => 'Media', 'description' => 'Image grid that opens in a lightbox.'];
    }

    public function defaults(): array
    {
        return ['images' => [], 'columns' => 3];
    }

    public function schema(): array
    {
        return Z::object([
            'images' => Z::array(
                Z::object(['src' => Z::str(Limits::URL), 'alt' => Z::str(Limits::LABEL), 'caption' => Z::str(Limits::LABEL)]),
                Limits::ALBUM_IMAGES,
                null,
                'An album holds at most '.Limits::ALBUM_IMAGES.' images.',
            ),
            'columns' => Z::literals([2, 3, 4], 3, 'Albums have 2, 3 or 4 columns.'),
        ]);
    }

    public function rules(array $props, Closure $report): void
    {
        if ($props['images'] === []) {
            $report('Add at least one image.', ['images']);
        }
        foreach ($props['images'] as $index => $image) {
            $at = ['images', $index];
            $number = $index + 1;
            if ($image['src'] === '') {
                $report("Image {$number}: choose an image.", [...$at, 'src']);
            } elseif (Urls::safeImageSrc($image['src']) === null) {
                $report("Image {$number}: this image address is not allowed.", [...$at, 'src']);
            }
            if (Js::blank($image['alt'])) {
                $report("Image {$number}: describe the image in the alt text.", [...$at, 'alt']);
            }
        }
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $p = $block['props'];
        $images = [];
        foreach ($p['images'] as $image) {
            $src = Urls::safeImageSrc($image['src']);
            if ($src !== null) {
                $images[] = ['src' => $src, 'alt' => $image['alt'], 'caption' => Js::trim($image['caption'])];
            }
        }
        if ($images === []) {
            return $ctx->editor
                ? '<figure class="rounded-xl border border-dashed border-border-strong bg-surface-alt p-8 text-center text-sm text-muted-foreground"'.$ctx->hooks($block).'>Add images in the inspector.</figure>'
                : '';
        }
        $items = '';
        foreach ($images as $image) {
            $items .= '<li><a href="'.RenderKit::esc($image['src']).'" '.self::ALBUM_ITEM_ATTR.($image['caption'] !== '' ? ' data-caption="'.RenderKit::esc($image['caption']).'"' : '').' class="block overflow-hidden rounded-xl border border-border bg-surface-alt focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"><img src="'.RenderKit::esc($image['src']).'" alt="'.RenderKit::esc($image['alt']).'" loading="lazy" decoding="async" class="aspect-square w-full object-cover transition-transform motion-safe:hover:scale-105"></a></li>';
        }

        return '<div '.self::ALBUM_ATTR.$ctx->hooks($block).'><ul class="grid '.RenderKit::pick(self::COLUMNS, (string) $p['columns'], '3').' gap-3">'.$items.'</ul></div>';
    }

    public function text(stdClass $props): array
    {
        $texts = [];
        foreach (TextKit::itemsOf($props->images ?? null) as $image) {
            if ($image instanceof stdClass) {
                $texts[] = TextKit::textOf($image->alt ?? null);
                $texts[] = TextKit::textOf($image->caption ?? null);
            }
        }

        return $texts;
    }
}
