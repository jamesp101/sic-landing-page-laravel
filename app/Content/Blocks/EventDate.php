<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use App\Content\Js;

/** Event block dates: `YYYY-MM-DD` or `YYYY-MM-DDTHH:mm`, local school time without a zone. */
final class EventDate
{
    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /**
     * @return array{year: int, month: int, day: int, hour: int|null, minute: int|null, iso: string}|null
     */
    public static function parseEventDate(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = Js::trim($value);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?$/D', $trimmed, $match)) {
            return null;
        }
        [$year, $month, $day] = [(int) $match[1], (int) $match[2], (int) $match[3]];
        // JavaScript's Date.UTC maps years 0-99 to 1900-1999, which the original round-trip check rejects.
        if ($year < 100 || ! checkdate($month, $day, $year)) {
            return null;
        }
        $hour = isset($match[4]) ? (int) $match[4] : null;
        $minute = isset($match[5]) ? (int) $match[5] : null;
        if ($hour !== null && ($hour > 23 || $minute > 59)) {
            return null;
        }

        return ['year' => $year, 'month' => $month, 'day' => $day, 'hour' => $hour, 'minute' => $minute, 'iso' => $trimmed];
    }

    /** @param array{year: int, month: int, day: int} $date */
    public static function formatEventDay(array $date): string
    {
        return self::MONTHS[$date['month'] - 1]." {$date['day']}, {$date['year']}";
    }

    /** @param array{hour: int|null, minute: int|null} $date */
    public static function formatEventTime(array $date): string
    {
        if ($date['hour'] === null || $date['minute'] === null) {
            return '';
        }
        $suffix = $date['hour'] >= 12 ? 'PM' : 'AM';
        $hour = $date['hour'] % 12 === 0 ? 12 : $date['hour'] % 12;

        return sprintf('%d:%02d %s', $hour, $date['minute'], $suffix);
    }

    /**
     * Sortable number for comparing two parsed dates.
     *
     * @param  array{year: int, month: int, day: int, hour: int|null, minute: int|null}  $date
     */
    public static function eventDateKey(array $date): int
    {
        return gmmktime($date['hour'] ?? 0, $date['minute'] ?? 0, 0, $date['month'], $date['day'], $date['year']);
    }
}
