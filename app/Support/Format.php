<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Dates in the fixed locale and zone (en-PH, Asia/Manila), so server and client text are identical.
 * Options: `time` (include the time of day), `short` (abbreviated month names).
 */
final class Format
{
    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    private const SHORT_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** Thin space around the dash of an ICU interval ("August 15 – 20, 2026"). */
    private const RANGE_DASH = "\u{2009}–\u{2009}";

    /** @param array{time?: bool, short?: bool} $opts */
    public static function formatDate(DateTimeInterface|string|null $date, array $opts = []): string
    {
        $value = self::toDate($date);

        return $value === null ? '' : self::format($value, $opts);
    }

    /**
     * "August 15 – 20, 2026" style ranges; falls back to one date when end is missing or the same day.
     *
     * @param  array{time?: bool, short?: bool}  $opts
     */
    public static function formatDateRange(DateTimeInterface|string|null $start, DateTimeInterface|string|null $end = null, array $opts = []): string
    {
        if ($start === null || $start === '') {
            return $end !== null && $end !== '' ? self::formatDate($end, $opts) : '';
        }
        if ($end === null || $end === '') {
            return self::formatDate($start, $opts);
        }
        $from = self::toDate($start);
        $to = self::toDate($end);
        if ($from === null || $to === null) {
            return self::formatDate($start, $opts);
        }

        $first = self::format($from, $opts);
        $last = self::format($to, $opts);
        if ($first === $last) {
            return $first;
        }
        if (! empty($opts['time'])) {
            return "{$first} – {$last}";
        }

        $months = ! empty($opts['short']) ? self::SHORT_MONTHS : self::MONTHS;
        [$y1, $m1, $d1] = self::parts($from);
        [$y2, $m2, $d2] = self::parts($to);
        if ($y1 !== $y2) {
            return "{$months[$m1 - 1]} {$d1}, {$y1}".self::RANGE_DASH."{$months[$m2 - 1]} {$d2}, {$y2}";
        }
        if ($m1 !== $m2) {
            return "{$months[$m1 - 1]} {$d1}".self::RANGE_DASH."{$months[$m2 - 1]} {$d2}, {$y1}";
        }

        return "{$months[$m1 - 1]} {$d1}".self::RANGE_DASH."{$d2}, {$y1}";
    }

    public static function formatFileSize(int|float $bytes): string
    {
        static $units = ['B', 'KB', 'MB', 'GB'];
        if (! is_finite((float) $bytes) || $bytes <= 0) {
            return '0 B';
        }
        $exponent = min((int) floor(log($bytes) / log(1024)), count($units) - 1);
        $value = $bytes / (1024 ** $exponent);
        $rounded = $exponent === 0 || $value >= 100 ? floor($value + 0.5) : floor($value * 10 + 0.5) / 10;

        return (floor($rounded) === $rounded ? (string) (int) $rounded : (string) $rounded).' '.$units[$exponent];
    }

    private static function toDate(DateTimeInterface|string|null $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            $date = $value instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($value)
                : new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('Asia/Manila'));
    }

    /** @return array{int, int, int} year, month, day in Manila */
    private static function parts(DateTimeImmutable $date): array
    {
        return [(int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j')];
    }

    /** @param array{time?: bool, short?: bool} $opts */
    private static function format(DateTimeImmutable $date, array $opts): string
    {
        [$year, $month, $day] = self::parts($date);
        $short = ! empty($opts['short']);
        $text = ($short ? self::SHORT_MONTHS : self::MONTHS)[$month - 1]." {$day}, {$year}";
        if (empty($opts['time'])) {
            return $text;
        }

        return $text.($short ? ', ' : ' at ').$date->format('g:i A');
    }
}
