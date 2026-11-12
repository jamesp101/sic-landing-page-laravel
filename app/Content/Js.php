<?php

declare(strict_types=1);

namespace App\Content;

use stdClass;

/**
 * The few JavaScript string semantics the ported TS code relies on: its whitespace set, UTF-16 lengths and
 * regex flavour. Keeps the HTML and plain text byte-identical to the TS renderer.
 */
final class Js
{
    /** Body of a character class equal to JavaScript's `\s`. */
    public const SPACE = '\x{9}-\x{d}\x{20}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}';

    /** JavaScript `.` (anything but a line terminator). */
    private const DOT = '[^\n\r\x{2028}\x{2029}]';

    /** @var array<string, string> */
    private static array $compiled = [];

    /**
     * Translates a JavaScript-flavoured pattern (`/body/flags`) to PCRE: `\s`, `\S` and `.` get their
     * JavaScript meaning and a flagless `$` only matches at the very end. Always UTF-8.
     */
    public static function rx(string $regex): string
    {
        if (isset(self::$compiled[$regex])) {
            return self::$compiled[$regex];
        }
        $end = strrpos($regex, '/');
        $body = substr($regex, 1, $end - 1);
        $flags = str_replace('g', '', substr($regex, $end + 1));
        $multiline = str_contains($flags, 'm');
        if (! str_contains($flags, 'u')) {
            $flags .= 'u';
        }

        $out = '';
        $class = null;
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];
            if ($char === '\\') {
                $next = $body[++$i] ?? '';
                if ($class !== null) {
                    $class .= '\\'.$next;
                } elseif ($next === 's') {
                    $out .= '['.self::SPACE.']';
                } elseif ($next === 'S') {
                    $out .= '[^'.self::SPACE.']';
                } else {
                    $out .= '\\'.$next;
                }
            } elseif ($class !== null) {
                if ($char === ']') {
                    // `[\s\S]` already means "anything"; any other class gets JavaScript's `\s`.
                    $out .= '['.(str_contains($class, '\\S') ? $class : str_replace('\\s', self::SPACE, $class)).']';
                    $class = null;
                } else {
                    $class .= $char;
                }
            } elseif ($char === '[') {
                $class = '';
            } elseif ($char === '.') {
                $out .= self::DOT;
            } elseif ($char === '$' && ! $multiline) {
                $out .= '\z';
            } else {
                $out .= $char;
            }
        }

        return self::$compiled[$regex] = '/'.$out.'/'.$flags;
    }

    /** JavaScript `String.prototype.trim`. */
    public static function trim(string $value): string
    {
        return (string) preg_replace('/^['.self::SPACE.']+|['.self::SPACE.']+$/Du', '', $value);
    }

    public static function trimEnd(string $value): string
    {
        return (string) preg_replace('/['.self::SPACE.']+$/Du', '', $value);
    }

    /** `!value.trim()` (a string "0" is not blank, unlike PHP's empty()). */
    public static function blank(string $value): bool
    {
        return self::trim($value) === '';
    }

    /** Replaces invalid UTF-8 so regexes with the `u` flag never fail (JS strings cannot be invalid). */
    public static function scrub(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
    }

    /** `value.length`: UTF-16 code units. */
    public static function length(string $value): int
    {
        if (! preg_match('/[^\x00-\x7f]/', $value)) {
            return strlen($value);
        }

        return intdiv(strlen((string) mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** `value.slice(0, $units)` without leaving half a surrogate pair. */
    public static function head(string $value, int $units): string
    {
        if (self::length($value) <= $units) {
            return $value;
        }
        $utf16 = (string) mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
        $cut = substr($utf16, 0, $units * 2);
        if ($units > 0) {
            $last = ord($cut[$units * 2 - 1]);
            if ($last >= 0xD8 && $last <= 0xDB) {
                $cut = substr($cut, 0, -2);
            }
        }

        return (string) mb_convert_encoding($cut, 'UTF-8', 'UTF-16LE');
    }

    /** The JSON type name zod messages use ("received …"). */
    public static function kind(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => is_float($value) && is_nan($value) ? 'nan' : 'number',
            is_string($value) => 'string',
            is_array($value) => 'array',
            $value instanceof stdClass => 'object',
            default => get_debug_type($value),
        };
    }

    public static function isObject(mixed $value): bool
    {
        return $value instanceof stdClass;
    }

    public static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /** `Number.isInteger` */
    public static function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
    }

    /** Property of a decoded JSON object, `null` when absent (or not an object). */
    public static function get(mixed $object, string $key): mixed
    {
        return $object instanceof stdClass ? ($object->{$key} ?? null) : null;
    }
}
