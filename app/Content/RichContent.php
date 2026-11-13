<?php

declare(strict_types=1);

namespace App\Content;

use App\Content\Blocks\PlainText;
use Closure;

/**
 * Rich content classified and parsed once ({@see Content::parseContent()}); `html`, `text`, `excerpt` and
 * `check` share that work.
 */
final class RichContent
{
    /** @var array<string, mixed> */
    private array $memo = [];

    /**
     * @param  'empty'|'blocks'|'markdown'  $format
     * @param  Closure(): string  $html
     * @param  Closure(): string  $text
     * @param  Closure(): array{ok: bool, value?: string, message?: string}  $check
     */
    public function __construct(
        public readonly string $format,
        private readonly Closure $html,
        private readonly Closure $text,
        private readonly Closure $check,
    ) {}

    /** Public HTML. */
    public function html(): string
    {
        return $this->once('html', $this->html);
    }

    /** Plain text with markup stripped and whitespace collapsed. */
    public function text(): string
    {
        return $this->once('text', $this->text);
    }

    /** Plain text cut to `Content::EXCERPT_LENGTH`. */
    public function excerpt(): string
    {
        return Content::clampExcerpt($this->text());
    }

    /**
     * Save-time gate: block documents are validated and re-serialised in normalised form (empty ones
     * become `''`), Markdown passes through untouched.
     *
     * @return array{ok: true, value: string}|array{ok: false, message: string}
     */
    public function check(): array
    {
        return $this->once('check', $this->check);
    }

    /** The write-side result of this parse: normalised value and `search_text` (see {@see Content::forStorage()}). */
    public function forStorage(): StoredContent
    {
        $checked = $this->check();
        if (! $checked['ok']) {
            return new StoredContent('', '');
        }

        // A block document's text comes from its normalised form, so `sic:backfill-search` finds nothing to change.
        $text = $this->format === 'blocks' ? PlainText::blocksToPlainText($checked['value']) : $this->text();

        return new StoredContent($checked['value'], $text);
    }

    private function once(string $key, Closure $compute): mixed
    {
        return array_key_exists($key, $this->memo) ? $this->memo[$key] : $this->memo[$key] = $compute();
    }
}
