<?php

namespace App\Carousel;

/**
 * String rules that must match JavaScript (stored documents were validated by the TS implementation):
 * `String.prototype.trim`, UTF-16 lengths and slices. Validation limits themselves count code points.
 */
final class JsText
{
    /** The characters `String.prototype.trim` and the regex `\s` match in JavaScript, as a PCRE class (use with /u). */
    public const WHITESPACE = '[\x{9}-\x{d}\x{20}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}]';

    public static function trim(string $value): string
    {
        return preg_replace('/^'.self::WHITESPACE.'+|'.self::WHITESPACE.'+$/u', '', $value) ?? $value;
    }

    /** Characters as the user counts them (zod string length limits). */
    public static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    /** JavaScript `string.length`. */
    public static function utf16Length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** JavaScript `string.slice(0, $units)` (never splits a character; a cut surrogate pair is dropped). */
    public static function slice(string $value, int $units): string
    {
        if (self::utf16Length($value) <= $units) {
            return $value;
        }
        $out = '';
        $used = 0;
        foreach (mb_str_split($value, 1, 'UTF-8') as $char) {
            $width = strlen($char) >= 4 ? 2 : 1;
            if ($used + $width > $units) {
                break;
            }
            $out .= $char;
            $used += $width;
        }

        return $out;
    }
}
