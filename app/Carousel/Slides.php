<?php

namespace App\Carousel;

use App\Models\CarouselItem;
use App\Storage\Storage;
use App\Storage\Variants;
use Closure;
use Illuminate\Support\Str;

/**
 * The Slide read model: stored carousel rows as the public Carousel and the admin designer see them. Both go
 * through `OverlaySchema::slideDocument` (a legacy slide arrives as Layers) and the one video rule, `slideVideoView`.
 */
final class Slides
{
    private const YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];

    /** Canonical watch URL a YouTube slide video is stored under. */
    public static function youtubeWatchUrl(string $id): string
    {
        return "https://www.youtube.com/watch?v={$id}";
    }

    /** The id of a YouTube watch / embed / shorts / live / youtu.be link; null for anything else (Vimeo included). */
    public static function youtubeId(string $raw): ?string
    {
        $url = JsText::trim($raw);
        if (preg_match('#^https?:#i', $url) !== 1) {
            return null;
        }
        $parts = parse_url(preg_replace('#^(https?:)/*#i', '$1//', $url) ?? $url);
        $host = $parts === false ? '' : strtolower($parts['host'] ?? '');
        if ($parts === false || $host === '') {
            return null;
        }
        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), fn (string $s): bool => $s !== ''));

        $id = null;
        if ($host === 'youtu.be') {
            $id = $segments[0] ?? null;
        } elseif (in_array($host, self::YOUTUBE_HOSTS, true)) {
            if (($segments[0] ?? null) === 'watch') {
                parse_str($parts['query'] ?? '', $query);
                $id = isset($query['v']) && is_string($query['v']) ? $query['v'] : null;
            } elseif (in_array($segments[0] ?? null, ['embed', 'shorts', 'live'], true)) {
                $id = $segments[1] ?? null;
            }
        }

        return $id !== null && preg_match('/^[A-Za-z0-9_-]{11}\z/', $id) === 1 ? $id : null;
    }

    /**
     * How a slide's background video plays: our file (by extension) or a YouTube id; null for none.
     *
     * @param  array{videoPath: ?string, videoUrl: ?string}  $row
     * @return array{kind: 'file', src: string, mime: string}|array{kind: 'youtube', id: string}|null
     */
    public static function slideVideoView(array $row): ?array
    {
        if (! empty($row['videoPath'])) {
            $src = Storage::publicUrl($row['videoPath']);

            return $src === null ? null : ['kind' => 'file', 'src' => $src, 'mime' => str_ends_with($row['videoPath'], '.webm') ? 'video/webm' : 'video/mp4'];
        }
        $id = ! empty($row['videoUrl']) ? self::youtubeId($row['videoUrl']) : null;

        return $id === null ? null : ['kind' => 'youtube', 'id' => $id];
    }

    /**
     * The public Carousel's Slides matching `$where`, in sequence order. `$where` is a Closure over the query or a
     * column map (`['showOnMain' => true]` / `['show_on_main' => true]`, `['departmentId' => 3]`).
     *
     * @param  Closure|array<string, mixed>  $where
     * @return list<array<string, mixed>>
     */
    public static function findSlides(Closure|array $where): array
    {
        $query = CarouselItem::query();
        if ($where instanceof Closure) {
            $query->where($where);
        } else {
            foreach ($where as $column => $value) {
                $query->where(Str::snake($column), $value);
            }
        }

        return $query->orderBy('order')->orderBy('id')->get()->map(function (CarouselItem $row): array {
            $doc = OverlaySchema::slideDocument(self::columns($row));

            return [
                'id' => $row->id,
                'imageUrl' => Storage::storedUrl($row->image_path),
                'imageMediumUrl' => Storage::variantUrl($row->image_path, Variants::SLIDE_WIDTH),
                'layers' => $doc['layers'],
                'mobile' => $doc['mobile'],
                'video' => self::slideVideoView(self::columns($row)),
            ];
        })->all();
    }

    /** @return array<string, mixed> The slide as the admin designer sees it (`DesignerSlide`). */
    public static function toDesignerSlide(CarouselItem $row): array
    {
        $doc = OverlaySchema::slideDocument(self::columns($row));
        $video = self::slideVideoView(self::columns($row));

        return [
            'id' => $row->id,
            'imageUrl' => Storage::storedUrl($row->image_path),
            'title' => $row->title,
            'order' => $row->order,
            'departmentId' => $row->department_id,
            'showOnMain' => $row->show_on_main,
            'layers' => $doc['layers'],
            'mobile' => $doc['mobile'],
            'video' => $video ? ($video['kind'] === 'file' ? $video['src'] : self::youtubeWatchUrl($video['id'])) : '',
            'videoKind' => $video['kind'] ?? null,
        ];
    }

    /** @return array{overlay: ?string, title: ?string, subtitle: ?string, linkUrl: ?string, videoPath: ?string, videoUrl: ?string} */
    private static function columns(CarouselItem $row): array
    {
        return [
            'overlay' => $row->overlay,
            'title' => $row->title,
            'subtitle' => $row->subtitle,
            'linkUrl' => $row->link_url,
            'videoPath' => $row->video_path,
            'videoUrl' => $row->video_url,
        ];
    }
}
