<?php

declare(strict_types=1);

namespace App\Support;

final class Html
{
    /** Escapes `& < > " '` (the apostrophe as `&#39;`, like the TS renderer). */
    public static function escape(string $value): string
    {
        return strtr($value, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);
    }

    /** TS name of {@see escape()}. */
    public static function escapeHtml(string $value): string
    {
        return self::escape($value);
    }
}
