<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use App\Content\Js;
use App\Support\Html;

/**
 * Inline markup used by text blocks: `**bold**`, `*italic*`, `[label](url)`, `\` escapes a following
 * character. Everything is emitted escaped; links pass through `safeHref`. Scans bytes: every token is
 * ASCII, so UTF-8 sequences pass through untouched.
 */
final class Inline
{
    /** Escapes text so it survives a round trip through {@see inlineToHtml()} as literal characters. */
    public static function escapeInline(string $text): string
    {
        return (string) preg_replace('/[\\\\*\[\]()]/', '\\\\$0', $text);
    }

    /** Inline markup to escaped HTML (newlines become `<br>`). */
    public static function inlineToHtml(string $text): string
    {
        return self::scan(Js::scrub($text), true, 0);
    }

    /** Inline markup to plain text. */
    public static function inlineToText(string $text): string
    {
        return self::scan(Js::scrub($text), false, 0);
    }

    /**
     * Splits on blank lines into trimmed, non-empty paragraphs.
     *
     * @return list<string>
     */
    public static function paragraphsOf(string $text): array
    {
        $parts = preg_split('/\n{2,}/', (string) preg_replace('/\r\n?/', "\n", $text)) ?: [];
        $parts = array_map(Js::trim(...), $parts);

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private static function findClose(string $text, int $from, string $token): int
    {
        $length = strlen($text);
        for ($i = $from; $i < $length; $i++) {
            if ($text[$i] === '\\') {
                $i++;

                continue;
            }
            if (substr_compare($text, $token, $i, strlen($token)) === 0) {
                // `*` must not be the first half of `**`.
                if ($token === '*' && ($text[$i + 1] ?? '') === '*') {
                    $i++;

                    continue;
                }

                return $i;
            }
        }

        return -1;
    }

    private static function text(string $value, bool $html): string
    {
        return $html ? Html::escape($value) : $value;
    }

    private static function scan(string $text, bool $html, int $depth): string
    {
        $out = '';
        $literal = '';
        $length = strlen($text);
        $flush = static function () use (&$out, &$literal, $html): void {
            if ($literal !== '') {
                $out .= self::text($literal, $html);
            }
            $literal = '';
        };

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            $next = $text[$i + 1] ?? '';
            if ($char === '\\' && $i + 1 < $length) {
                $literal .= $text[++$i];
            } elseif ($char === "\n") {
                $flush();
                $out .= $html ? '<br>' : ' ';
            } elseif ($depth < 4 && $char === '*' && $next === '*') {
                $end = self::findClose($text, $i + 2, '**');
                if ($end > $i + 2) {
                    $flush();
                    $inner = self::scan(substr($text, $i + 2, $end - $i - 2), $html, $depth + 1);
                    $out .= $html ? '<strong class="font-bold text-primary">'.$inner.'</strong>' : $inner;
                    $i = $end + 1;
                } else {
                    $literal .= '**';
                    $i++;
                }
            } elseif ($depth < 4 && $char === '*') {
                $end = self::findClose($text, $i + 1, '*');
                if ($end > $i + 1) {
                    $flush();
                    $inner = self::scan(substr($text, $i + 1, $end - $i - 1), $html, $depth + 1);
                    $out .= $html ? '<em class="italic">'.$inner.'</em>' : $inner;
                    $i = $end;
                } else {
                    $literal .= $char;
                }
            } elseif ($depth < 4 && $char === '[') {
                $mid = self::findClose($text, $i + 1, '](');
                $end = $mid === -1 ? -1 : self::findClose($text, $mid + 2, ')');
                if ($mid > $i + 1 && $end > $mid + 2) {
                    $flush();
                    $inner = self::scan(substr($text, $i + 1, $mid - $i - 1), $html, $depth + 1);
                    $href = Urls::safeHref(preg_replace('/\\\\([^\n\r])/', '$1', substr($text, $mid + 2, $end - $mid - 2)));
                    $out .= $html ? self::link($inner, $href) : $inner;
                    $i = $end;
                } else {
                    $literal .= $char;
                }
            } else {
                $literal .= $char;
            }
        }
        $flush();

        return $out;
    }

    private static function link(string $inner, ?string $href): string
    {
        if ($href === null) {
            return $inner;
        }
        $external = Urls::isExternalHref($href) ? ' target="_blank" rel="noopener noreferrer"' : '';

        return '<a href="'.Html::escape($href).'" class="font-medium text-link underline underline-offset-2 hover:no-underline"'.$external.'>'.$inner.'</a>';
    }
}
