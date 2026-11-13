<?php

namespace App\Storage;

/**
 * Derived image variants: smaller WebPs stored next to an original under `<key without extension>.w<width>.webp`
 * (`posts/abc.webp` → `posts/abc.w640.webp`). Each kind of image has its own widths: Highlight images
 * (`posts/<name>`) 640 px for cards and the Post hero, Slide photos (`carousels/<name>`) 960 px for the first
 * paint, Department logos (`departments/<name>`) and Member portraits (`members/<name>`) 128 px for the cards and
 * the org chart. Variants are written in the same `StoredFiles::save` commit as the original, removed
 * with it, and kept by the storage cleanup while the original is. Pure key logic, free of the storage disk.
 */
final class Variants
{
    /** Width in pixels of the Highlight image variant. */
    public const CARD_WIDTH = 640;

    /** Width in pixels of the Slide photo variant. */
    public const SLIDE_WIDTH = 960;

    /** Width in pixels of the Department logo and Member portrait variant. */
    public const THUMB_WIDTH = 128;

    private const IMAGE_TYPES = '(webp|jpe?g|png|gif|avif)';

    /** Variant widths per image kind, by key pattern. */
    private const KINDS = [
        ['posts', [self::CARD_WIDTH]],
        ['carousels', [self::SLIDE_WIDTH]],
        ['departments', [self::THUMB_WIDTH]],
        ['members', [self::THUMB_WIDTH]],
    ];

    private const IMAGE_EXTENSIONS = ['.webp', '.jpg', '.jpeg', '.png', '.gif', '.avif'];

    /** The most original keys the route looks for behind one missing variant (a HEAD each): `.webp` first, as every upload is WebP. */
    private const MAX_ORIGINAL_CANDIDATES = 4;

    /** Key without its extension (`posts/a.webp` → `posts/a`). */
    private static function stem(string $key): string
    {
        return preg_replace('/\.[^.\/]+$/', '', $key);
    }

    private static function suffixPattern(): string
    {
        $widths = array_unique(array_merge(...array_map(fn (array $kind) => $kind[1], self::KINDS)));

        return '/\.w('.implode('|', $widths).')\.webp$/D';
    }

    /** True for a key that is itself a variant. */
    public static function isVariantKey(string $key): bool
    {
        return preg_match(self::suffixPattern(), $key) === 1;
    }

    /** @return list<int> Widths of the variants an original has (empty for a key that is not an original of a kind that has them). */
    public static function variantWidths(string $key): array
    {
        if (self::isVariantKey($key)) {
            return [];
        }
        foreach (self::KINDS as [$prefix, $widths]) {
            if (preg_match('#^'.$prefix.'/[^/]+\.'.self::IMAGE_TYPES.'$#iD', $key) === 1) {
                return $widths;
            }
        }

        return [];
    }

    /** The variant key of an original at `$width`, else null when its kind has no such variant. */
    public static function variantKeyOf(string $key, int $width): ?string
    {
        return in_array($width, self::variantWidths($key), true) ? self::stem($key).".w{$width}.webp" : null;
    }

    /** @return list<string> Every variant key of an original (empty when it has none). */
    public static function variantKeysOf(string $key): array
    {
        return array_map(fn (int $width) => self::stem($key).".w{$width}.webp", self::variantWidths($key));
    }

    /** The key an original and its variants share, without extension or suffix (`posts/a`). */
    public static function variantBase(string $key): string
    {
        return self::isVariantKey($key) ? preg_replace(self::suffixPattern(), '', $key) : self::stem($key);
    }

    /**
     * Keys an original of `$variantKey` may have, most likely first, at most four (the extension is not part of the
     * variant key). Empty unless `$variantKey` is a variant its kind really has (`posts/a.w640.webp`, not
     * `downloads/a.w640.webp` or `posts/a.w960.webp`), so a request for any other key never probes the store.
     *
     * @return list<string>
     */
    public static function originalCandidates(string $variantKey): array
    {
        if (! self::isVariantKey($variantKey)) {
            return [];
        }
        $base = self::variantBase($variantKey);
        $candidates = [];
        foreach (self::IMAGE_EXTENSIONS as $ext) {
            $candidate = $base.$ext;
            if (in_array($variantKey, self::variantKeysOf($candidate), true)) {
                $candidates[] = $candidate;
            }
        }

        return array_slice($candidates, 0, self::MAX_ORIGINAL_CANDIDATES);
    }
}
