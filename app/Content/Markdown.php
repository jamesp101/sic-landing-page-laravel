<?php

declare(strict_types=1);

namespace App\Content;

use App\Content\Blocks\Urls;
use App\Support\Html;

/**
 * Lightweight Markdown + MDX-lite renderer (Legacy Markdown). Pure functions; the output equals the TS
 * renderer's (`resources/js/lib/markdown.ts`, also used by the admin editor preview).
 *
 * - All text is HTML-escaped; only the MDX-lite tags are turned into markup:
 *   <Card title="…">, <Center>, <Accordion title="…"> (rendered as <details>), <Badges>, <Flex>.
 * - Content headings are shifted one level down (`#` → h2 … capped at h6) so the page keeps its own h1.
 * - Output carries Tailwind token classes (no `prose`, no hard-coded colours).
 */
final class Markdown
{
    private const OPEN = "\u{E000}";

    private const CLOSE = "\u{E001}";

    private const CLASSES = [
        'heading' => [
            2 => 'mt-8 mb-3 text-2xl font-bold text-primary',
            3 => 'mt-6 mb-2 text-xl font-bold text-primary',
            4 => 'mt-5 mb-2 text-lg font-semibold text-primary',
            5 => 'mt-4 mb-2 text-base font-semibold text-primary',
            6 => 'mt-4 mb-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground',
        ],
        'p' => 'my-3 leading-relaxed text-foreground',
        'ul' => 'my-3 list-disc space-y-1 pl-6 text-foreground marker:text-gold-600',
        'ol' => 'my-3 list-decimal space-y-1 pl-6 text-foreground marker:text-gold-600',
        'li' => 'leading-relaxed',
        'blockquote' => 'my-4 border-l-4 border-gold-500 pl-4 italic text-muted-foreground',
        'pre' => 'my-4 overflow-x-auto rounded-lg border border-border bg-surface-alt p-4 font-mono text-sm text-foreground',
        'code' => 'rounded bg-surface-alt px-1.5 py-0.5 font-mono text-[0.9em] text-foreground',
        'strong' => 'font-bold text-primary',
        'em' => 'italic',
        'a' => 'font-medium text-link underline-offset-2 hover:underline',
        'img' => 'my-4 h-auto max-w-full rounded-xl',
        'card' => 'my-6 rounded-2xl border border-border bg-surface p-6 shadow-brand-sm',
        'cardTitle' => 'mb-2 text-lg font-bold text-primary',
        'cardBody' => 'text-sm text-muted-foreground',
        'center' => 'my-6 flex flex-col items-center justify-center text-center',
        'flex' => 'my-6 flex flex-col items-stretch gap-6 md:flex-row',
        'details' => 'group my-4 rounded-xl border border-border bg-surface-alt p-4',
        'summary' => 'flex cursor-pointer list-none items-center justify-between font-semibold text-primary',
        'chevron' => 'h-4 w-4 transition-transform group-open:rotate-180',
        'detailsBody' => 'mt-3 text-sm text-muted-foreground',
        'badges' => 'my-2 flex flex-wrap gap-1.5',
        'badge' => 'inline-flex items-center rounded-full bg-brand-100 px-2.5 py-0.5 text-xs font-semibold text-brand-700',
    ];

    private const IMPORT_LINE = '/^import\s+.*\s+from\s+[\'"].*[\'"];?\s*$/gm';

    public static function escapeHtml(string $value): string
    {
        return Html::escape($value);
    }

    /** Plain-text rendition of Markdown / MDX-lite (markup stripped, whitespace collapsed). */
    public static function stripMarkdown(?string $markdown): string
    {
        if ($markdown === null || $markdown === '') {
            return '';
        }
        $out = Js::scrub($markdown);
        $steps = [
            ['/\r\n?/g', "\n"],
            [self::IMPORT_LINE, ' '],
            ['/```[^\n]*\n?/g', ' '],
            ['/<Badges\s+tags="([^"]*)"\s*\/>/g', ' $1 '],
            ['/<\/?[A-Za-z][^>]*>/g', ' '],
            ['/!\[([^\]]*)\]\([^)]*\)/g', ' '],
            ['/\[([^\]]+)\]\([^)]*\)/g', '$1'],
            ['/^\s{0,3}#{1,6}\s+/gm', ''],
            ['/^\s*>\s?/gm', ''],
            ['/^\s*(?:[-*+]|\d+[.)])\s+/gm', ''],
            ['/(\*\*|__|\*|`)/g', ''],
            ['/\s+/g', ' '],
        ];
        foreach ($steps as [$pattern, $replacement]) {
            $out = (string) preg_replace(Js::rx($pattern), $replacement, $out);
        }

        return Js::trim($out);
    }

    public static function parseMarkdown(?string $markdown): string
    {
        if ($markdown === null || $markdown === '') {
            return '';
        }

        $stash = [];
        $source = Js::scrub($markdown);
        $source = (string) preg_replace('/\r\n?/', "\n", $source);
        $source = str_replace([self::OPEN, self::CLOSE], '', $source);
        $source = (string) preg_replace(Js::rx(self::IMPORT_LINE), '', $source);

        // Tags first (before escaping), then escape everything else.
        $withCode = (string) preg_replace_callback(
            Js::rx('/^[ \t]*```[^\n]*\n([\s\S]*?)\n?[ \t]*```[ \t]*$/gm'),
            static function (array $match) use (&$stash): string {
                $stash[] = '<pre class="'.self::CLASSES['pre'].'"><code>'.Html::escape($match[1]).'</code></pre>';

                return "\n".self::OPEN.(count($stash) - 1).self::CLOSE."\n";
            },
            $source,
        );
        $lines = explode("\n", self::extractTags($withCode, $stash));

        $html = [];
        $paragraph = [];
        $flushParagraph = static function () use (&$paragraph, &$html, &$stash): void {
            if ($paragraph === []) {
                return;
            }
            $html[] = '<p class="'.self::CLASSES['p'].'">'.self::inline(Html::escape(implode("\n", $paragraph)), $stash).'</p>';
            $paragraph = [];
        };

        $heading = Js::rx('/^(#{1,6})\s+(.*?)\s*$/');
        $quote = Js::rx('/^\s*>\s?(.*)$/');
        $ulItem = Js::rx('/^\s*[-*+]\s+(.*)$/');
        $olItem = Js::rx('/^\s*\d+[.)]\s+(.*)$/');
        $sentinelLine = '/^'.self::OPEN.'(\d+)'.self::CLOSE.'$/Du';

        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            $line = $lines[$i];
            $trimmed = Js::trim($line);

            if ($trimmed === '') {
                $flushParagraph();

                continue;
            }

            if (preg_match($sentinelLine, $trimmed, $sentinel)) {
                $flushParagraph();
                $html[] = $stash[(int) $sentinel[1]];

                continue;
            }

            if (preg_match($heading, $line, $match)) {
                $flushParagraph();
                $level = min(strlen($match[1]) + 1, 6);
                $html[] = "<h{$level} class=\"".self::CLASSES['heading'][$level].'">'.self::inline(Html::escape($match[2]), $stash)."</h{$level}>";

                continue;
            }

            if (preg_match($quote, $line)) {
                $flushParagraph();
                $quoted = [];
                for (; $i < $count && preg_match($quote, $lines[$i], $inner); $i++) {
                    $quoted[] = $inner[1];
                }
                $i--;
                $html[] = '<blockquote class="'.self::CLASSES['blockquote'].'">'.self::inline(Html::escape(implode("\n", $quoted)), $stash).'</blockquote>';

                continue;
            }

            $ordered = ! preg_match($ulItem, $line) && preg_match($olItem, $line);
            if ($ordered || preg_match($ulItem, $line)) {
                $flushParagraph();
                $item = $ordered ? $olItem : $ulItem;
                $items = [];
                for (; $i < $count && preg_match($item, $lines[$i], $inner); $i++) {
                    $items[] = '<li class="'.self::CLASSES['li'].'">'.self::inline(Html::escape($inner[1]), $stash).'</li>';
                }
                $i--;
                $tag = $ordered ? 'ol' : 'ul';
                $html[] = "<{$tag} class=\"".self::CLASSES[$tag].'">'.implode('', $items)."</{$tag}>";

                continue;
            }

            $paragraph[] = $trimmed;
        }
        $flushParagraph();

        if ($html === []) {
            return '';
        }

        return '<div data-sic-markdown>'.self::restore(implode("\n", $html), $stash).'</div>';
    }

    /** Splits a comma separated list into escaped badge spans. */
    private static function badges(string $list): string
    {
        $html = '<div class="'.self::CLASSES['badges'].'">';
        foreach (explode(',', $list) as $item) {
            $item = Js::trim($item);
            if ($item !== '') {
                $html .= '<span class="'.self::CLASSES['badge'].'">'.Html::escape($item).'</span>';
            }
        }

        return $html.'</div>';
    }

    /**
     * Replaces MDX-lite tags with line-level sentinels pointing into `$stash`.
     *
     * @param  list<string>  $stash
     */
    private static function extractTags(string $source, array &$stash): string
    {
        $put = static function (string $html) use (&$stash): string {
            $stash[] = $html;

            return "\n".self::OPEN.(count($stash) - 1).self::CLOSE."\n";
        };
        /** @var list<array{name: string, close: string}> $stack */
        $stack = [];
        $open = static function (string $name, string $html, string $close) use (&$stack, $put): string {
            $stack[] = ['name' => $name, 'close' => $close];

            return $put($html);
        };
        $closeTag = static function (string $name) use (&$stack, $put): string {
            $index = -1;
            foreach ($stack as $position => $entry) {
                if ($entry['name'] === $name) {
                    $index = $position;
                }
            }
            if ($index === -1) {
                return "\n";
            }
            $closing = array_reverse(array_splice($stack, $index));

            return $put(implode('', array_column($closing, 'close')));
        };

        $chevron = '<svg class="'.self::CLASSES['chevron'].'" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>';

        $tag = Js::rx('/<Badges\s+tags="([^"]*)"\s*\/>|<Badges>([\s\S]*?)<\/Badges>|<Card(?:\s+title="([^"]*)")?\s*>|<Accordion(?:\s+title="([^"]*)")?\s*>|<(Center|Flex)>|<\/(Card|Accordion|Center|Flex)>/g');

        $out = (string) preg_replace_callback($tag, static function (array $m) use ($put, $open, $closeTag, $chevron): string {
            [$match, $badgeAttr, $badgeBody, $cardTitle, $accTitle, $simple, $closing] = array_pad($m, 7, null);
            if ($badgeAttr !== null) {
                return $put(self::badges($badgeAttr));
            }
            if ($badgeBody !== null) {
                return $put(self::badges($badgeBody));
            }
            if ($closing !== null && $closing !== '') {
                return $closeTag($closing);
            }
            if ($simple === 'Center') {
                return $open('Center', '<div data-sic-center class="'.self::CLASSES['center'].'">', '</div>');
            }
            if ($simple === 'Flex') {
                return $open('Flex', '<div class="'.self::CLASSES['flex'].'">', '</div>');
            }
            if ($accTitle !== null || str_starts_with($match, '<Accordion')) {
                return $open(
                    'Accordion',
                    '<details class="'.self::CLASSES['details'].'"><summary class="'.self::CLASSES['summary'].'"><span>'.Html::escape($accTitle ?? '').'</span>'.$chevron.'</summary><div class="'.self::CLASSES['detailsBody'].'">',
                    '</div></details>',
                );
            }
            $title = $cardTitle !== null && $cardTitle !== '' ? '<h3 class="'.self::CLASSES['cardTitle'].'">'.Html::escape($cardTitle).'</h3>' : '';

            return $open('Card', '<div class="'.self::CLASSES['card'].'">'.$title.'<div class="'.self::CLASSES['cardBody'].'">', '</div></div>');
        }, $source, -1, $replaced, PREG_UNMATCHED_AS_NULL);

        // Close anything left open so the surrounding layout stays intact.
        if ($stack !== []) {
            $stash[] = implode('', array_column(array_reverse($stack), 'close'));
            $out .= "\n".self::OPEN.(count($stash) - 1).self::CLOSE;
        }

        return $out;
    }

    private static function emphasis(string $text): string
    {
        $text = (string) preg_replace(Js::rx('/\*\*(?=\S)(.+?)(?<=\S)\*\*/g'), '<strong class="'.self::CLASSES['strong'].'">$1</strong>', $text);

        return (string) preg_replace(Js::rx('/(?<![*\w])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![*\w])/g'), '<em class="'.self::CLASSES['em'].'">$1</em>', $text);
    }

    /**
     * Inline formatting on already-escaped text. Generated markup is stashed to protect it from later passes.
     *
     * @param  list<string>  $stash
     */
    private static function inline(string $text, array &$stash): string
    {
        $keep = static function (string $html) use (&$stash): string {
            $stash[] = $html;

            return self::OPEN.(count($stash) - 1).self::CLOSE;
        };

        $out = (string) preg_replace_callback('/`([^`]+)`/u', static fn (array $m): string => $keep('<code class="'.self::CLASSES['code'].'">'.$m[1].'</code>'), $text);

        $out = (string) preg_replace_callback(Js::rx('/!\[([^\]]*)\]\(([^)\s]+)\)/g'), static fn (array $m): string => $keep(
            '<img src="'.(Urls::safeImageSrc($m[2]) ?? '#').'" alt="'.$m[1].'" loading="lazy" decoding="async" class="'.self::CLASSES['img'].'">',
        ), $out);

        $out = (string) preg_replace_callback(Js::rx('/\[([^\]]+)\]\(([^)\s]+)\)/g'), static function (array $m) use ($keep): string {
            $external = (bool) preg_match('#^https?://#i', $m[2]);
            $attrs = $external ? ' target="_blank" rel="noopener"' : '';

            // `href` is already HTML-escaped; anything outside the block-editor URL allowlist becomes `#`.
            return $keep('<a href="'.(Urls::safeHref($m[2]) ?? '#').'" class="'.self::CLASSES['a'].'"'.$attrs.'>'.self::emphasis($m[1]).'</a>');
        }, $out);

        return self::emphasis($out);
    }

    /** @param list<string> $stash */
    private static function restore(string $html, array $stash): string
    {
        $out = $html;
        // Stashed entries may themselves contain sentinels (link text with code spans).
        for ($guard = 0; $guard < 5 && str_contains($out, self::OPEN); $guard++) {
            $out = (string) preg_replace_callback('/'.self::OPEN.'(\d+)'.self::CLOSE.'/u', static fn (array $m): string => $stash[(int) $m[1]] ?? '', $out);
        }

        return $out;
    }
}
