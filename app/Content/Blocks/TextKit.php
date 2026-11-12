<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use App\Content\Js;

/** Tolerant readers for plain-text extraction: stored props may have any shape (decoded JSON). */
final class TextKit
{
    public static function textOf(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** @return list<mixed> */
    public static function itemsOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** Text with inline markup (`**bold**`, links) reduced to its words. */
    public static function richOf(mixed $value): string
    {
        return Inline::inlineToText(self::textOf($value));
    }

    public static function get(mixed $object, string $key): mixed
    {
        return Js::get($object, $key);
    }
}
