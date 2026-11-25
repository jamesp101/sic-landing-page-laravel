<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use App\Support\Html;

/** HTML helpers and class tables shared by the Block type modules. */
final class RenderKit
{
    public const GAP = ['none' => 'gap-0', 'sm' => 'gap-2', 'md' => 'gap-4', 'lg' => 'gap-8'];

    public const TEXT_ALIGN = ['inherit' => '', 'left' => 'text-left', 'center' => 'text-center', 'right' => 'text-right', 'justify' => 'text-justify'];

    public static function esc(string $value): string
    {
        return Html::escape($value);
    }

    /**
     * Lookup that never returns inherited/undefined entries for odd stored values.
     *
     * @param  array<array-key, string>  $table
     */
    public static function pick(array $table, mixed $key, string|int $fallback): string
    {
        return is_string($key) && array_key_exists($key, $table) ? $table[$key] : $table[$fallback];
    }

    /** Joins the non-empty class parts with a space. */
    public static function joinClasses(string|false|null ...$parts): string
    {
        return implode(' ', array_filter($parts, static fn (string|false|null $part): bool => $part !== false && $part !== null && $part !== ''));
    }

    /**
     * One `<p>` per paragraph. `$field` is the canvas mark of the prop (`RenderContext::field`, `''` publicly); each
     * paragraph then also carries its index so the canvas edits one paragraph of the prop at a time.
     */
    public static function paragraphsHtml(string $text, string $className, string $field = ''): string
    {
        $html = '';
        foreach (Inline::paragraphsOf($text) as $index => $part) {
            $html .= '<p class="'.$className.'"'.($field !== '' ? $field.' data-edit-part="'.$index.'"' : '').'>'.Inline::inlineToHtml($part).'</p>';
        }

        return $html;
    }

    public static function flow(string $inner, string $gap = 'gap-4'): string
    {
        return '<div class="flex flex-col '.$gap.'">'.$inner.'</div>';
    }
}
