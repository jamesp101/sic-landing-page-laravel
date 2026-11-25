<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\Limits;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\RenderKit;
use App\Content\Blocks\Urls;
use App\Content\Blocks\Z;
use App\Content\Js;
use Closure;
use stdClass;

final class VideoBlock extends BlockType
{
    public function meta(): array
    {
        return ['label' => 'Video', 'group' => 'Media', 'description' => 'YouTube, Vimeo or an uploaded video.'];
    }

    public function defaults(): array
    {
        return ['url' => '', 'title' => '', 'caption' => ''];
    }

    public function schema(): array
    {
        return Z::object([
            'url' => Z::str(Limits::URL),
            'title' => Z::str(Limits::LABEL),
            'caption' => Z::str(Limits::LABEL),
        ]);
    }

    public function rules(array $props, Closure $report): void
    {
        if (Urls::parseVideoUrl($props['url']) === null) {
            $report('Use a YouTube or Vimeo link, or an uploaded video.', ['url']);
        }
        if (Js::blank($props['title'])) {
            $report('Add a video title for screen readers.', ['title']);
        }
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $p = $block['props'];
        $source = Urls::parseVideoUrl($p['url']);
        if ($source === null || Js::blank($p['title'])) {
            return '';
        }
        $caption = ! Js::blank($p['caption'])
            ? '<figcaption class="mt-2 text-sm text-muted-foreground"'.$ctx->field('caption', 'plain').'>'.RenderKit::esc($p['caption']).'</figcaption>'
            : '';
        if ($ctx->editor) {
            $frame = '<div class="flex h-full w-full items-center justify-center p-4 text-center text-sm font-semibold text-muted-foreground">Video: '.RenderKit::esc($p['title']).'</div>';
        } elseif ($source['provider'] === 'file') {
            $frame = '<video controls preload="metadata" playsinline aria-label="'.RenderKit::esc($p['title']).'" title="'.RenderKit::esc($p['title']).'" class="h-full w-full bg-brand-950"><source src="'.RenderKit::esc($source['embedUrl']).'" type="'.$source['mime'].'"></video>';
        } else {
            $frame = '<iframe src="'.RenderKit::esc($source['embedUrl']).'" title="'.RenderKit::esc($p['title']).'" loading="lazy" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" sandbox="allow-scripts allow-same-origin allow-presentation allow-popups" class="h-full w-full"></iframe>';
        }

        return '<figure'.$ctx->hooks($block).'><div class="aspect-video overflow-hidden rounded-xl border border-border bg-surface-alt">'.$frame.'</div>'.$caption.'</figure>';
    }

    public function text(stdClass $props): array
    {
        return [];
    }
}
