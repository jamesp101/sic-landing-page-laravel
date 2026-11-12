<?php

declare(strict_types=1);

namespace App\Support;

use App\Content\Js;

final class Initials
{
    /** Leading titles skipped so "Dr. Alan Turing" gives "AT", not "DT". */
    private const HONORIFIC = '/^(dr|prof|mr|mrs|ms|mx|engr|atty|fr|sr|sis|br|rev|hon)\.?$/iD';

    public static function getInitials(?string $fullName = null): string
    {
        if ($fullName === null || $fullName === '') {
            return '';
        }

        $all = array_values(array_filter(
            preg_split(Js::rx('/\s+/u'), Js::trim(Js::scrub($fullName))) ?: [],
            static fn (string $part): bool => $part !== '',
        ));
        $last = count($all) - 1;
        $withoutTitles = array_values(array_filter(
            $all,
            static fn (string $part, int $index): bool => $index >= $last || ! preg_match(self::HONORIFIC, $part),
            ARRAY_FILTER_USE_BOTH,
        ));
        $names = $withoutTitles !== [] ? $withoutTitles : $all;

        if ($names === []) {
            return '';
        }
        if (count($names) === 1) {
            return mb_strtoupper(self::initial($names[0]));
        }

        return mb_strtoupper(self::initial($names[0]).self::initial($names[count($names) - 1]));
    }

    private static function initial(string $name): string
    {
        return mb_substr($name, 0, 1);
    }
}
