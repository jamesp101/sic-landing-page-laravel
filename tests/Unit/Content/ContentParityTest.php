<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Content\Blocks\Renderer;
use App\Content\Blocks\Schema;
use App\Content\Content;
use PHPUnit\Framework\Attributes\DataProvider;

/** Every case of `fixtures/content.json` / `editor.json` was rendered by the TS implementation. */
final class ContentParityTest extends FixtureTestCase
{
    public static function contentCases(): array
    {
        return self::cases('content');
    }

    public static function editorCases(): array
    {
        return self::cases('editor');
    }

    #[DataProvider('contentCases')]
    public function test_content_matches_the_ts_implementation(array $case): void
    {
        $input = self::input($case);
        $parsed = Content::parseContent($input);

        $this->assertSame($case['format'], $parsed->format);
        $this->assertSame($case['html'], $parsed->html());
        $this->assertSame($case['text'], $parsed->text());
        $this->assertSame($case['excerpt'], $parsed->excerpt());
        $this->assertSame($case['check'], $parsed->check());
        $this->assertSame($case['searchText'], Content::searchTextOf($input));
        $this->assertSame($case['hasAlbum'], Content::hasAlbum($parsed->html()));
        $this->assertSame($case['html'], Content::renderContent($input));
    }

    #[DataProvider('editorCases')]
    public function test_editor_canvas_html_matches_the_ts_implementation(array $case): void
    {
        $result = Schema::parseBlockDocument($case['input']);
        $this->assertTrue($result['ok']);
        $this->assertSame($case['html'], Renderer::renderBlocks($result['document']['blocks'], ['editor' => true]));
    }
}
