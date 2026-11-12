<?php

namespace App\Admin;

/** URL slug helpers. */
final class Slug
{
    /** Longest slug root: leaves room for a `-<n>` suffix inside the 255-character slug columns. */
    private const MAX_ROOT = 240;

    /** Lower-case ASCII slug: accents stripped, every other run of characters becomes one `-`, no edge dashes. */
    public static function slugify(string $input): string
    {
        $decomposed = \Normalizer::normalize($input, \Normalizer::FORM_KD);
        $stripped = preg_replace('/[\x{0300}-\x{036F}]/u', '', $decomposed === false ? $input : $decomposed) ?? '';
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($stripped));

        return trim((string) $slug, '-');
    }

    /**
     * Slugifies `$base` (empty result becomes `$fallback`), cut to MAX_ROOT characters, and appends `-1`,
     * `-2`, … until `$exists` reports the candidate as free.
     *
     * @param  callable(string): bool  $exists
     */
    public static function uniqueSlug(string $base, callable $exists, string $fallback = 'post'): string
    {
        $root = rtrim(substr(self::slugify($base), 0, self::MAX_ROOT), '-');
        if ($root === '') {
            $root = $fallback;
        }
        $slug = $root;
        $count = 1;
        while ($exists($slug)) {
            $slug = $root.'-'.$count++;
        }

        return $slug;
    }
}
