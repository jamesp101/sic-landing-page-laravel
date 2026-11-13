<?php

declare(strict_types=1);

namespace App\Content;

use App\Content\Blocks\PlainText;
use App\Content\Blocks\Renderer;
use App\Content\Blocks\Schema;
use App\Content\Blocks\Types\AlbumBlock;

/**
 * Rich content: the one place that decides whether a stored value is a Block document or Legacy Markdown
 * (posts, hero sections, `welcome_hero_content`) and what comes out of it.
 *
 * Rule: content that opens with `{"type":"sic-blocks"` (leading whitespace ignored) is a Block document.
 * If it does not parse or validate it renders `''`, has an empty Excerpt and fails `check` - its JSON never
 * reaches a page. Anything else is Markdown / MDX-lite, whose headings already sit below the page's single
 * h1 (Markdown `#` renders as h2, block headings are h2-h4), so no further shifting happens here.
 */
final class Content
{
    /** Longest Excerpt (characters, ellipsis included). */
    public const EXCERPT_LENGTH = 200;

    public static function clampExcerpt(string $text): string
    {
        if (Js::length($text) <= self::EXCERPT_LENGTH) {
            return $text;
        }

        return Js::trimEnd(Js::head($text, self::EXCERPT_LENGTH - 1)).'…';
    }

    /** Whether a stored value claims to be a block document (even a truncated or malformed one). */
    public static function isBlockDocument(?string $content): bool
    {
        return $content !== null && $content !== ''
            && (bool) preg_match(Js::rx('/^\s*\{\s*"type"\s*:\s*"'.Blocks\Limits::DOCUMENT_TYPE.'"/'), $content);
    }

    /** The last parsed value and its parse: validating a value and then storing it (see `forStorage`) parses once. */
    private static ?string $lastContent = null;

    private static ?RichContent $lastParsed = null;

    public static function parseContent(?string $content): RichContent
    {
        $content ??= '';
        if ($content !== self::$lastContent || self::$lastParsed === null) {
            self::$lastParsed = self::classify($content);
            self::$lastContent = $content;
        }

        return self::$lastParsed;
    }

    private static function classify(string $content): RichContent
    {
        $source = Js::scrub($content);
        $format = $source === '' ? 'empty' : (self::isBlockDocument($source) ? 'blocks' : 'markdown');

        if ($format === 'blocks') {
            $parsed = null;
            $parse = static function () use (&$parsed, $source): array {
                return $parsed ??= Schema::parseBlockJson($source);
            };

            return new RichContent(
                $format,
                static function () use ($parse): string {
                    $result = $parse()['result'];

                    return $result['ok'] ? Renderer::renderBlocks($result['document']['blocks']) : '';
                },
                static fn (): string => PlainText::documentToPlainText($parse()['raw']),
                static function () use ($parse): array {
                    $result = $parse()['result'];
                    if (! $result['ok']) {
                        return ['ok' => false, 'message' => $result['issues'][0]['message'] ?? 'Invalid content.'];
                    }
                    if ($result['document']['blocks'] === []) {
                        return ['ok' => true, 'value' => ''];
                    }

                    return ['ok' => true, 'value' => Schema::serialize($result['document'])];
                },
            );
        }

        return new RichContent(
            $format,
            static fn (): string => $format === 'empty' ? '' : Markdown::parseMarkdown($source),
            static fn (): string => $format === 'empty' ? '' : Markdown::stripMarkdown($source),
            static fn (): array => ['ok' => true, 'value' => $source],
        );
    }

    /** Stored content → HTML for public pages. */
    public static function renderContent(?string $content): string
    {
        return self::parseContent($content)->html();
    }

    /** Whether rendered content (`renderContent`/`html()`) contains an album, i.e. needs the public lightbox script. */
    public static function hasAlbum(string $html): bool
    {
        return str_contains($html, ' '.AlbumBlock::ALBUM_ATTR);
    }

    /** Stored content → Excerpt of at most `EXCERPT_LENGTH` characters. */
    public static function excerptOf(?string $content): string
    {
        return self::parseContent($content)->excerpt();
    }

    /**
     * Save-time gate for a content column (see {@see RichContent::check()}).
     *
     * @return array{ok: true, value: string}|array{ok: false, message: string}
     */
    public static function checkContent(string $content): array
    {
        return self::parseContent($content)->check();
    }

    /**
     * The one write entry for a Rich content column: validated input → what to store (normalised value plus
     * the `search_text` of Posts and Hero sections), from one parse. Call it after `ContentField::rules()`
     * accepted the input; an invalid value gives empty strings. The Welcome hero and Department hero content
     * take `->value` only.
     */
    public static function forStorage(?string $content): StoredContent
    {
        return self::parseContent($content)->forStorage();
    }
}
