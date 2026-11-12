<?php

namespace App\Storage;

use Intervention\Image\Format;
use Throwable;

/** Derived smaller WebPs (`Variants`) of stored images. */
final class ImageVariant
{
    private const VARIANT_QUALITY = 78;

    /** Prefixes whose every stored image has a variant: Highlight images, Department logos, Member portraits. */
    private const BACKFILL_PREFIXES = ['posts/', 'departments/', 'members/'];

    /** The `$width` px variant of an image as WebP bytes (never upscaled), or null for an animated image, which keeps its original. */
    public static function variantOf(string $bytes, int $width): ?string
    {
        ImageWebp::inspect($bytes);
        $image = ImageWebp::manager()->decodeBinary($bytes);
        if ($image->isAnimated()) {
            return null;
        }

        $image->orient()->scaleDown(width: $width);
        ImageWebp::stripMetadata($image);

        return $image->encodeUsingFormat(Format::WEBP, quality: self::VARIANT_QUALITY)->toString();
    }

    /**
     * Writes the variants of the stored image `$key` (given as `$bytes`), except those in `$skip`, and returns their
     * keys; empty when the key has none or the image is animated. Throws when the image cannot be decoded or stored.
     *
     * @param  list<string>  $skip  variant keys that already exist
     * @return list<string>
     */
    public static function putVariants(string $key, string $bytes, array $skip = []): array
    {
        $widths = array_values(array_filter(
            Variants::variantWidths($key),
            fn (int $width) => ! in_array(Variants::variantKeyOf($key, $width), $skip, true),
        ));
        $written = [];
        foreach ($widths as $width) {
            $variant = self::variantOf($bytes, $width);
            if ($variant === null) {
                return $written;
            }
            $variantKey = Variants::variantKeyOf($key, $width);
            Storage::putBytes($variantKey, $variant, 'image/webp');
            $written[] = $variantKey;
        }

        return $written;
    }

    /**
     * Creates the missing variants of every stored image that should have one: every Highlight image under
     * `posts/`, every Department logo and Member portrait, and the given Slide photo keys (`carousels/` also holds
     * Layer images, which have none). Safe to run again.
     *
     * @param  iterable<string>  $slidePhotoKeys
     * @return array{created: int, existing: int, skipped: list<array{key: string, reason: string}>}
     */
    public static function backfillVariants(iterable $slidePhotoKeys = []): array
    {
        $report = ['created' => 0, 'existing' => 0, 'skipped' => []];
        $keys = [];
        foreach ($slidePhotoKeys as $key) {
            $keys[$key] = true;
        }
        foreach (self::BACKFILL_PREFIXES as $prefix) {
            foreach (Storage::list($prefix) as $object) {
                $keys[$object['key']] = true;
            }
        }
        foreach (array_keys($keys) as $key) {
            $wanted = Variants::variantKeysOf($key);
            if ($wanted === []) {
                continue;
            }
            $present = array_values(array_filter($wanted, fn (string $variant) => Storage::head($variant) !== null));
            $report['existing'] += count($present);
            if (count($present) === count($wanted)) {
                continue;
            }
            try {
                $object = Storage::get($key);
                if ($object === null) {
                    continue;
                }
                $written = self::putVariants($key, $object->contents(), $present);
                $report['created'] += count($written);
                if ($written === []) {
                    $report['skipped'][] = ['key' => $key, 'reason' => 'animated image'];
                }
            } catch (Throwable $error) {
                $report['skipped'][] = ['key' => $key, 'reason' => $error->getMessage()];
            }
        }

        return $report;
    }
}
