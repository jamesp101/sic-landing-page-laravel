<?php

namespace App\Storage;

/**
 * HTTP `Range` support shared by the storage drivers and the `/storage` route. Only a single `bytes=` range is
 * honoured; anything else is ignored (the full object is served), as RFC 9110 allows.
 *
 * A byte range is `['start' => int, 'end' => ?int]` (end inclusive, optional) or `['suffix' => int]` (the last
 * bytes). A resolved range is `['start' => int, 'end' => int]`, inclusive.
 */
final class Range
{
    /**
     * Parses a `Range` header value; null when it is absent, not a single `bytes` range or malformed.
     *
     * @return array{start: int, end?: int|null}|array{suffix: int}|null
     */
    public static function parseRangeHeader(?string $header): ?array
    {
        // At most 15 digits per number (< 2^53), so every parsed value is a safe integer.
        if (preg_match('/^bytes=(\d{0,15})-(\d{0,15})$/D', trim($header ?? ''), $match) !== 1) {
            return null;
        }
        [, $first, $last] = $match;
        if ($first === '' && $last === '') {
            return null;
        }
        if ($first === '') {
            return ['suffix' => (int) $last];
        }
        $start = (int) $first;
        if ($last === '') {
            return ['start' => $start];
        }
        $end = (int) $last;

        // `bytes=5-2` is malformed, not unsatisfiable: ignore it.
        return $end < $start ? null : ['start' => $start, 'end' => $end];
    }

    /**
     * Resolves `$range` against an object of `$size` bytes; null when it selects no byte (416).
     *
     * @param  array{start?: int, end?: int|null, suffix?: int}  $range
     * @return array{start: int, end: int}|null
     */
    public static function resolveRange(array $range, int $size): ?array
    {
        if ($size <= 0) {
            return null;
        }
        if (isset($range['suffix'])) {
            return $range['suffix'] <= 0 ? null : ['start' => max(0, $size - $range['suffix']), 'end' => $size - 1];
        }
        if ($range['start'] >= $size) {
            return null;
        }

        return ['start' => $range['start'], 'end' => min($range['end'] ?? $size - 1, $size - 1)];
    }

    /** The `Range` header value S3 expects for `$range`. */
    public static function rangeHeaderValue(array $range): string
    {
        if (isset($range['suffix'])) {
            return 'bytes=-'.$range['suffix'];
        }

        return 'bytes='.$range['start'].'-'.($range['end'] ?? '');
    }
}
