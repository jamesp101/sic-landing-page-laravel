<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Content\Blocks\Renderer;
use App\Content\Content;
use App\Content\Js;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Classification, excerpt and save-time gate of Rich content (cases of the TS `content.test.ts`). */
final class ContentTest extends TestCase
{
    private const VALID = '{"type":"sic-blocks","version":1,"blocks":[{"id":"h1","type":"heading","props":{"text":"Welcome","level":2,"align":"inherit"}},{"id":"p1","type":"paragraph","props":{"text":"Hello","size":"base","tone":"default","align":"inherit"}}]}';

    public static function classificationCases(): array
    {
        $truncated = substr(self::VALID, 0, 60);

        return [
            'valid document' => [self::VALID, 'blocks', 'Welcome Hello'],
            'truncated document' => [$truncated, 'blocks', ''],
            'document with leading whitespace' => ["\n  ".self::VALID, 'blocks', 'Welcome Hello'],
            'Markdown' => ["# Title\n\nSome **text**", 'markdown', 'Title Some text'],
            'whitespace-only Markdown' => [" \n\t\n ", 'markdown', ''],
            'import-only Markdown' => ["import Foo from './foo';\n\nimport Bar from \"./bar\"", 'markdown', ''],
            'empty string' => ['', 'empty', ''],
            'null' => [null, 'empty', ''],
        ];
    }

    #[DataProvider('classificationCases')]
    public function test_render_and_plain_text_agree(?string $content, string $format, string $text): void
    {
        $parsed = Content::parseContent($content);

        $this->assertSame($format, $parsed->format);
        $this->assertSame($text, $parsed->text());
        $this->assertSame($text, Content::parseContent($content)->text());
        $this->assertSame($parsed->html(), Content::renderContent($content));
        // Nothing readable: no text and no HTML, from the same decision.
        $this->assertSame($text === '', $parsed->html() === '');
        if ($format === 'blocks') {
            $this->assertStringNotContainsString('sic-blocks"', $parsed->html());
            $this->assertStringNotContainsString('{', $parsed->text());
        }
    }

    public function test_the_markers_wrap_the_output(): void
    {
        $this->assertStringStartsWith('<div class="flex flex-col gap-5" data-sic-blocks>', Content::renderContent(self::VALID));
        $this->assertStringStartsWith('<div data-sic-markdown>', Content::renderContent('Text'));
        $this->assertStringContainsString('<div data-sic-center class="', Content::renderContent("<Center>\nMiddle\n</Center>"));
    }

    public function test_the_excerpt_is_cut_to_the_limit_with_an_ellipsis(): void
    {
        $excerpt = Content::excerptOf(str_repeat('word ', 100));

        $this->assertLessThanOrEqual(Content::EXCERPT_LENGTH, Js::length($excerpt));
        $this->assertStringEndsWith('…', $excerpt);
        $this->assertSame('Short text', Content::excerptOf('Short text'));
        $this->assertSame('', Content::excerptOf(substr(self::VALID, 0, 60)));
    }

    public function test_the_excerpt_never_ends_in_half_a_surrogate_pair(): void
    {
        $excerpt = Content::clampExcerpt(str_repeat('é', 198).str_repeat('😀', 5));

        $this->assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
        $this->assertSame(str_repeat('é', 198).'…', $excerpt);
    }

    public function test_check_passes_markdown_and_empty_values_through(): void
    {
        $this->assertSame(['ok' => true, 'value' => '# A'], Content::parseContent('# A')->check());
        $this->assertSame(['ok' => true, 'value' => ''], Content::parseContent('')->check());
        $this->assertSame(['ok' => true, 'value' => '{"a":1}'], Content::checkContent('{"a":1}'));
        $this->assertSame(['ok' => true, 'value' => '{curly} notes on sic-blocks'], Content::checkContent('{curly} notes on sic-blocks'));
    }

    public function test_check_normalises_valid_documents_and_stores_empty_ones_as_an_empty_string(): void
    {
        $input = '{"type":"sic-blocks","version":1,"blocks":[{"id":"a1","type":"paragraph","props":{"text":"Hi","evil":"<script>"}}]}';
        $checked = Content::checkContent($input);

        $this->assertTrue($checked['ok']);
        $this->assertSame('{"type":"sic-blocks","version":1,"blocks":[{"id":"a1","type":"paragraph","props":{"text":"Hi","size":"base","tone":"default","align":"inherit"}}]}', $checked['value']);
        $this->assertSame(['ok' => true, 'value' => ''], Content::checkContent('{"type":"sic-blocks","version":1,"blocks":[]}'));
    }

    public function test_check_rejects_invalid_block_documents_with_an_editor_facing_message(): void
    {
        $unsafe = Content::checkContent('{"type":"sic-blocks","version":1,"blocks":[{"id":"a","type":"button","props":{"label":"Go","href":"javascript:alert(1)"}}]}');
        $this->assertFalse($unsafe['ok']);
        $this->assertStringContainsString('valid link', $unsafe['message']);

        $this->assertFalse(Content::checkContent('{"type":"sic-blocks","version":1,"blocks":[{"id":"a","type":"nope"}]}')['ok']);
        $this->assertFalse(Content::parseContent(substr(self::VALID, 0, 60))->check()['ok']);
    }

    public function test_a_markdown_block_with_nothing_readable_shows_the_editor_hint_only_in_the_editor(): void
    {
        foreach ([" \n ", "import Foo from './foo';"] as $source) {
            $block = ['id' => 'm', 'type' => 'markdown', 'props' => ['source' => $source]];
            $this->assertStringContainsString('Empty Markdown block', Renderer::renderBlocks([$block], ['editor' => true]));
            $this->assertSame('', Renderer::renderBlocks([$block]));
        }
    }

    public function test_has_album_finds_the_lightbox_hook(): void
    {
        $document = '{"type":"sic-blocks","version":1,"blocks":[{"id":"a","type":"album","props":{"images":[{"src":"/a.png","alt":"A","caption":""}],"columns":3}}]}';

        $this->assertTrue(Content::hasAlbum(Content::renderContent($document)));
        $this->assertFalse(Content::hasAlbum(Content::renderContent(self::VALID)));
    }

    public function test_for_storage_gives_the_normalised_value_with_its_search_text_from_one_parse(): void
    {
        $stored = Content::forStorage(self::VALID);
        $this->assertSame('Welcome Hello', $stored->searchText);
        $this->assertSame(Content::checkContent(self::VALID)['value'], $stored->value);

        $markdown = Content::forStorage("# Title\n\nSome **text**");
        $this->assertSame("# Title\n\nSome **text**", $markdown->value);
        $this->assertSame('Title Some text', $markdown->searchText);

        $this->assertSame('', Content::forStorage(null)->value);
        $this->assertSame('', Content::forStorage(null)->searchText);
        $this->assertSame('', Content::forStorage('{"type":"sic-blocks","version":1,"blocks":[]}')->value);
    }

    public function test_for_storage_search_text_is_stable_under_normalising_again(): void
    {
        $input = '{"type":"sic-blocks","version":1,"blocks":[{"id":"a1","type":"paragraph","props":{"text":"Hi","evil":"<script>"}}]}';
        $first = Content::forStorage($input);

        $this->assertSame($first->searchText, Content::forStorage($first->value)->searchText);
        $this->assertSame($first->value, Content::forStorage($first->value)->value);
    }

    public function test_for_storage_of_an_invalid_document_stores_nothing(): void
    {
        $stored = Content::forStorage(substr(self::VALID, 0, 60));

        $this->assertSame('', $stored->value);
        $this->assertSame('', $stored->searchText);
    }

    public function test_invalid_utf8_is_scrubbed_instead_of_breaking_the_output(): void
    {
        $html = Content::renderContent("caf\xE9 **bold**");

        $this->assertTrue(mb_check_encoding($html, 'UTF-8'));
        $this->assertStringContainsString('<strong', $html);
    }
}
