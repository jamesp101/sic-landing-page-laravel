<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use App\Content\Js;
use stdClass;

/** Plain-text extraction from a stored block document (excerpts, meta descriptions, feeds). Tolerates malformed JSON. */
final class PlainText
{
    /** Joins every readable text of an already parsed document with single spaces; `''` when it is not an object. */
    public static function documentToPlainText(mixed $parsed): string
    {
        if (! $parsed instanceof stdClass) {
            return '';
        }
        $parts = [];
        self::collect(TextKit::itemsOf($parsed->blocks ?? null), $parts);

        return Js::trim((string) preg_replace(Js::rx('/\s+/g'), ' ', implode(' ', $parts)));
    }

    /** Joins every readable text of a stored document string; `''` when it does not parse. */
    public static function blocksToPlainText(string $content): string
    {
        $raw = json_decode($content, false, 4096);

        return $raw === null && json_last_error() !== JSON_ERROR_NONE ? '' : self::documentToPlainText($raw);
    }

    /** @param list<mixed> $blocks @param list<string> $out */
    private static function collect(array $blocks, array &$out): void
    {
        foreach ($blocks as $raw) {
            if (! $raw instanceof stdClass) {
                continue;
            }
            // Each Block type reads its own props; a type the registry does not know contributes nothing.
            if (Registry::isBlockType($raw->type ?? null)) {
                $props = $raw->props ?? null;
                array_push($out, ...Registry::get($raw->type)->text($props instanceof stdClass ? $props : new stdClass));
            }
            self::collect(TextKit::itemsOf($raw->children ?? null), $out);
        }
    }
}
