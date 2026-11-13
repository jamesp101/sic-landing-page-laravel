<?php

namespace App\Carousel;

use App\Models\CarouselItem;
use App\Storage\Storage;
use App\Storage\StoredFiles;
use App\Storage\StoredKeyReferences;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Slide writes that own files. A slide owns its photo, an uploaded background video (`video_path`) and the layer
 * images under `carousels/` nothing else uses (declared by `CarouselItem::fileOwnership()`); they are written,
 * replaced and deleted only through `StoredFiles::save` / `delete`, and copied on duplicate.
 *
 * Slide content is `['overlay' => ?string, 'title' => ?string, 'subtitle' => ?string, 'linkUrl' => ?string]`;
 * a slide video is `['videoPath' => ?string, 'videoUrl' => ?string]` (at most one is set).
 */
final class CarouselAdmin
{
    /**
     * Parses the designer's single `video` field: '' = none, one of our uploaded videos (`videos/…`) = `videoPath`,
     * a YouTube link = `videoUrl` (stored in its canonical watch form). Anything else is rejected.
     *
     * @return array{ok: true, video: array{videoPath: ?string, videoUrl: ?string}}|array{ok: false, error: string}
     */
    public static function parseSlideVideo(string $input): array
    {
        $value = JsText::trim($input);
        if ($value === '') {
            return ['ok' => true, 'video' => ['videoPath' => null, 'videoUrl' => null]];
        }
        $key = Storage::videoKeyFromUrl($value);
        if ($key !== null) {
            return ['ok' => true, 'video' => ['videoPath' => $key, 'videoUrl' => null]];
        }
        $id = Slides::youtubeId($value);
        if ($id !== null) {
            return ['ok' => true, 'video' => ['videoPath' => null, 'videoUrl' => Slides::youtubeWatchUrl($id)]];
        }

        return ['ok' => false, 'error' => 'Use a YouTube link or upload an mp4 or webm video.'];
    }

    /**
     * True when a stored video is referenced by anything other than slide `$exceptId` (`StoredKeyReferences`' rule:
     * another slide, any content, any setting). A slide may only adopt a video nothing else uses, so replacing or
     * deleting its video can never remove another row's file.
     */
    public static function videoUsedElsewhere(string $key, ?int $exceptId = null): bool
    {
        return StoredKeyReferences::isReferenced($key, (new CarouselItem)->getTable(), $exceptId);
    }

    /** Parses the designer's `video` field and checks the slide (`$slideId`, none for a new slide) may own that file. */
    public static function claimSlideVideo(string $raw, ?int $slideId = null): array
    {
        $parsed = self::parseSlideVideo($raw);
        if (! $parsed['ok'] || $parsed['video']['videoPath'] === null) {
            return $parsed;
        }
        $videoPath = $parsed['video']['videoPath'];
        $current = $slideId === null ? null : CarouselItem::query()->whereKey($slideId)->value('video_path');
        $error = $current === $videoPath ? null : self::videoClaimError($videoPath, $slideId);

        return $error === null ? $parsed : ['ok' => false, 'error' => $error];
    }

    /**
     * Saves a slide's design, replacement photo and video in one commit. The video file belongs to the row: a newly
     * adopted upload is removed if the commit fails, the old file is removed after a replace or removal.
     * `$input['video']` absent/null keeps the current one.
     *
     * @param  array{content: array, image?: ?UploadedFile, video?: ?array}  $input
     */
    public static function updateSlide(CarouselItem $row, array $input): CarouselItem
    {
        $video = $input['video'] ?? ['videoPath' => $row->video_path, 'videoUrl' => $row->video_url];

        return StoredFiles::save($row, [
            ...self::contentColumns($input['content']),
            'video_path' => $video['videoPath'],
            'video_url' => $video['videoUrl'],
        ], ['image_path' => $input['image'] ?? null]);
    }

    /** Deletes a slide and every file it owned: photo, uploaded video and layer images nothing else uses. */
    public static function deleteSlide(CarouselItem $row): array
    {
        $id = $row->id;
        StoredFiles::delete($row);

        return ['id' => $id];
    }

    /**
     * Creates a slide at the end of the sequence; its photo is stored and an adopted video upload becomes the row's.
     *
     * @param  array{content: array, image: UploadedFile, video: array, departmentId: ?int, showOnMain: bool}  $input
     */
    public static function createSlide(array $input): CarouselItem
    {
        $last = (int) CarouselItem::query()->max('order');

        return StoredFiles::save(new CarouselItem, [
            ...self::contentColumns($input['content']),
            'video_path' => $input['video']['videoPath'],
            'video_url' => $input['video']['videoUrl'],
            'order' => $last + 1,
            'department_id' => $input['departmentId'],
            'show_on_main' => $input['showOnMain'],
        ], ['image_path' => $input['image']]);
    }

    /**
     * Copies a slide right after the original, shifting later slides down. The copy owns its own files (photo, video
     * and layer images), so deleting either slide never breaks the other; they are removed again if the commit fails.
     */
    public static function duplicateSlide(CarouselItem $row): CarouselItem
    {
        $temporary = [];
        try {
            $uploads = [
                'image_path' => self::copyOf($row->image_path, $temporary),
                'video_path' => $row->video_path ? self::copyOf($row->video_path, $temporary) : null,
            ];
            $layerFiles = [];
            $layerCopies = [];
            foreach (self::overlayImageSrcs($row->overlay) as $index => $src) {
                $key = self::ownedImageKey($src);
                if ($key === null) {
                    continue;
                }
                $copy = self::copyOf($key, $temporary);
                $layerFiles["layer{$index}"] = $copy === null ? null : ['prefix' => 'carousels', 'file' => $copy];
                $layerCopies[$src] = "layer{$index}";
            }
            $doc = OverlaySchema::parseOverlayDocument($row->overlay);

            return StoredFiles::save(
                new CarouselItem,
                function (array $keys) use ($row, $doc, $layerCopies): array {
                    $overlay = $row->overlay;
                    if ($doc !== null) {
                        $layers = array_map(function (array $layer) use ($keys, $layerCopies): array {
                            $copied = $layer['type'] === 'image' ? ($keys[$layerCopies[$layer['src']] ?? ''] ?? null) : null;

                            return $copied ? [...$layer, 'src' => Storage::storedUrl($copied)] : $layer;
                        }, $doc['layers']);
                        $overlay = Overlay::serializeOverlay($layers, $doc['mobile']);
                    }

                    return [
                        'title' => $row->title,
                        'subtitle' => $row->subtitle,
                        'link_url' => $row->link_url,
                        'overlay' => $overlay,
                        'video_url' => $row->video_url,
                        'order' => $row->order + 1,
                        'department_id' => $row->department_id,
                        'show_on_main' => $row->show_on_main,
                        'image_path' => $keys['image_path'] ?? $row->image_path,
                    ];
                },
                $uploads,
                $layerFiles,
                function (CarouselItem $copy, array $attributes) use ($row): void {
                    DB::transaction(function () use ($copy, $attributes, $row): void {
                        CarouselItem::query()->where('order', '>', $row->order)->increment('order');
                        $copy->fill($attributes)->save();
                    });
                },
            );
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * Image URLs referenced by the image layers of a stored overlay.
     *
     * @return list<string>
     */
    public static function overlayImageSrcs(?string $raw): array
    {
        $srcs = [];
        foreach (OverlaySchema::parseOverlay($raw) ?? [] as $layer) {
            if ($layer['type'] === 'image') {
                $srcs[$layer['src']] = true;
            }
        }

        return array_map('strval', array_keys($srcs));
    }

    /** Storage key of a layer image uploaded under `carousels/`; null for anything else (never delete foreign files). */
    public static function ownedImageKey(string $src): ?string
    {
        $key = Storage::keyFromPublicUrl($src);

        return $key !== null && preg_match('#^carousels/[A-Za-z0-9._-]+\z#', $key) === 1 ? $key : null;
    }

    /**
     * Keys of the given layer images that no slide references any more (as layer or as slide photo).
     * Call after the row that referenced them was updated or deleted; pass the result to the stored-files cleanup.
     *
     * @param  list<string>  $srcs
     * @return list<string>
     */
    public static function unusedLayerImageKeys(array $srcs): array
    {
        $unused = [];
        foreach ($srcs as $src) {
            $key = self::ownedImageKey($src);
            if ($key === null) {
                continue;
            }
            // Match the bare key: with S3_PUBLIC_URL one key has two URL forms (route and CDN).
            $used = CarouselItem::query()->whereRaw('instr(overlay, ?) > 0', [$key])->exists() || CarouselItem::query()->where('image_path', $key)->exists();
            if (! $used) {
                $unused[] = $key;
            }
        }

        return $unused;
    }

    /**
     * New `order` values for slides listed in their new sequence. Reuses the existing values (sorted) so slides
     * outside the reordered subset keep their relative position, forcing strictly increasing values so ties
     * (for example all zeros) become a real order.
     *
     * @param  list<int>  $currentOrders
     * @return list<int>
     */
    public static function assignOrders(array $currentOrders): array
    {
        sort($currentOrders);
        for ($index = 1; $index < count($currentOrders); $index++) {
            $currentOrders[$index] = max($currentOrders[$index], $currentOrders[$index - 1] + 1);
        }

        return $currentOrders;
    }

    /** Error message when `$key` cannot become the video of slide `$slideId` (a new slide has no id), else null. */
    private static function videoClaimError(string $key, ?int $slideId): ?string
    {
        if (Storage::headUpload($key) === null) {
            return 'The uploaded video was not found. Upload it again.';
        }
        if (self::videoUsedElsewhere($key, $slideId)) {
            return 'This video is already used elsewhere. Upload it again for this slide.';
        }

        return null;
    }

    /** @return array{overlay: ?string, title: ?string, subtitle: ?string, link_url: ?string} */
    private static function contentColumns(array $content): array
    {
        return [
            'overlay' => $content['overlay'],
            'title' => $content['title'],
            'subtitle' => $content['subtitle'],
            'link_url' => $content['linkUrl'],
        ];
    }

    /**
     * A copy of a stored object, ready to store again; null when the source is missing.
     *
     * @param  list<string>  $temporary  collects the temp files to delete after the commit
     */
    private static function copyOf(string $key, array &$temporary): ?UploadedFile
    {
        $stored = Storage::read($key);
        if ($stored === null) {
            return null;
        }
        $path = tempnam(sys_get_temp_dir(), 'slide');
        file_put_contents($path, $stored->contents());
        $temporary[] = $path;

        return new UploadedFile($path, basename($key), $stored->contentType ?? 'application/octet-stream', null, true);
    }
}
