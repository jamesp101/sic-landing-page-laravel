<?php

namespace App\Storage;

/**
 * Output box per image use, shared with the browser compression (`resources/js/lib/image-compress.ts`): sized for
 * the largest place the image is shown (incl. 2x screens). Quality is WebP 0..1.
 */
final class ImageTargets
{
    public const TARGETS = [
        /** Carousel slides: full-width 16:9 hero. */
        'slide' => ['maxWidth' => 1920, 'maxHeight' => 1080, 'quality' => 0.82],
        /** Post highlight images (cards + post header + Open Graph). */
        'highlight' => ['maxWidth' => 1600, 'maxHeight' => 1600, 'quality' => 0.82],
        /** Images placed inside post / hero-section content. */
        'content' => ['maxWidth' => 1600, 'maxHeight' => 1600, 'quality' => 0.82],
        /** Department logos (shown up to ~256 px). */
        'logo' => ['maxWidth' => 512, 'maxHeight' => 512, 'quality' => 0.9],
        /** Org chart member photos (shown up to ~160 px). */
        'portrait' => ['maxWidth' => 480, 'maxHeight' => 480, 'quality' => 0.85],
        /** Logos / images placed as carousel layers. */
        'layer' => ['maxWidth' => 1200, 'maxHeight' => 1200, 'quality' => 0.88],
    ];

    /** @return array{maxWidth: int, maxHeight: int, quality: float} */
    public static function get(string $name): array
    {
        return self::TARGETS[$name] ?? throw new \InvalidArgumentException("Unknown image target {$name}");
    }

    /** `photo.JPG` -> `photo.webp`; empty or extension-less names get a default stem. */
    public static function webpName(string $name): string
    {
        $stem = trim((string) preg_replace('/\.[^.\/\\\\]+$/', '', $name));

        return ($stem !== '' ? $stem : 'image').'.webp';
    }
}
