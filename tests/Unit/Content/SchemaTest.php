<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Content\Blocks\Limits;
use App\Content\Blocks\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The rejections and normalisation of `validateDocument` (cases of the TS `schema.test.ts`). */
final class SchemaTest extends TestCase
{
    private static function raw(array $blocks): array
    {
        return ['type' => 'sic-blocks', 'version' => 1, 'blocks' => $blocks];
    }

    private static function leaf(string $type, array $props = [], string $id = 'a1'): array
    {
        return ['id' => $id, 'type' => $type, 'props' => (object) $props];
    }

    private static function validate(array $input): array
    {
        return Schema::validateDocument(json_decode(json_encode($input), false));
    }

    private static function messages(array $result): array
    {
        return $result['ok'] ? [] : array_column($result['issues'], 'message');
    }

    private function assertRejects(array $result, string $text): void
    {
        $this->assertFalse($result['ok']);
        $this->assertTrue(
            array_reduce(self::messages($result), static fn (bool $found, string $message): bool => $found || str_contains($message, $text), false),
            "No issue contains \"{$text}\": ".json_encode(self::messages($result)),
        );
    }

    private function assertOk(array $blocks, string $note = ''): void
    {
        $this->assertTrue(self::validate(self::raw($blocks))['ok'], $note);
    }

    private function assertInvalid(array $blocks, string $note = ''): void
    {
        $this->assertFalse(self::validate(self::raw($blocks))['ok'], $note);
    }

    public function test_an_empty_document_is_valid_and_keeps_its_shape(): void
    {
        $this->assertSame(['ok' => true, 'document' => ['type' => 'sic-blocks', 'version' => 1, 'blocks' => []]], self::validate(self::raw([])));
    }

    public function test_values_that_are_not_block_documents_are_rejected(): void
    {
        foreach ([null, 'text', 42, (object) [], ['type' => 'sic-blocks'], [...self::raw([]), 'version' => 2], [...self::raw([]), 'type' => 'x'], [...self::raw([]), 'blocks' => 'nope']] as $input) {
            $this->assertFalse(Schema::validateDocument(json_decode(json_encode($input), false))['ok'], json_encode($input));
        }
    }

    public function test_unknown_block_types_are_rejected(): void
    {
        $this->assertRejects(self::validate(self::raw([self::leaf('script', ['src' => 'x'])])), 'Unknown block type');
    }

    public function test_unknown_props_are_dropped_and_defaults_filled(): void
    {
        $result = self::validate(self::raw([self::leaf('paragraph', ['text' => 'Hi', 'onclick' => 'alert(1)', 'style' => 'x'])]));

        $this->assertTrue($result['ok']);
        $this->assertSame(['text' => 'Hi', 'size' => 'base', 'tone' => 'default', 'align' => 'inherit', 'color' => '', 'background' => ''], $result['document']['blocks'][0]['props']);
    }

    public function test_missing_props_are_defaults(): void
    {
        $this->assertTrue(self::validate(self::raw([['id' => 'a1', 'type' => 'paragraph']]))['ok']);
    }

    public function test_invalid_prop_values_are_rejected(): void
    {
        $this->assertInvalid([self::leaf('spacer', ['size' => 'huge'])]);
        $this->assertInvalid([self::leaf('paragraph', ['text' => 5])]);
        $this->assertInvalid([self::leaf('grid', ['columns' => 9])]);
        $this->assertInvalid([self::leaf('columns', ['count' => 4])]);
    }

    public function test_bad_and_duplicate_ids_are_rejected(): void
    {
        $this->assertInvalid([self::leaf('divider', [], 'a b')]);
        $this->assertInvalid([self::leaf('divider', [], '')]);
        $this->assertInvalid([self::leaf('divider', [], str_repeat('x', 33))]);
        $this->assertRejects(self::validate(self::raw([self::leaf('divider', [], 'same'), self::leaf('spacer', [], 'same')])), 'Duplicate element id');
    }

    public function test_children_on_a_non_container_are_rejected(): void
    {
        $this->assertRejects(self::validate(self::raw([['id' => 'a1', 'type' => 'paragraph', 'props' => (object) [], 'children' => []]])), 'cannot contain');
    }

    public function test_sections_only_belong_at_the_top_level(): void
    {
        $section = static fn (string $id, array $children): array => ['id' => $id, 'type' => 'section', 'props' => (object) [], 'children' => $children];
        $this->assertRejects(self::validate(self::raw([$section('s1', [$section('s2', [])])])), 'Sections can only be placed at the top level');
        $this->assertRejects(self::validate(self::raw([['id' => 'r1', 'type' => 'row', 'props' => (object) [], 'children' => [$section('s2', [])]]])), 'Sections can only be placed at the top level');
        $this->assertOk([$section('s1', [])]);
    }

    public function test_issues_carry_the_block_id(): void
    {
        $result = self::validate(self::raw([self::leaf('image', ['src' => '/a.png'], 'img1')]));

        $this->assertFalse($result['ok']);
        $this->assertContains('img1', array_column($result['issues'], 'blockId'));
    }

    public function test_images_need_alt_text_unless_decorative(): void
    {
        $this->assertRejects(self::validate(self::raw([self::leaf('image', ['src' => 'https://example.com/a.png', 'alt' => ''])])), 'alt');
        $this->assertRejects(self::validate(self::raw([self::leaf('image', ['src' => '/a.png', 'alt' => '   '])])), 'alt');
        $this->assertOk([self::leaf('image', ['src' => '/storage/a.png', 'alt' => 'A tree'])]);
        $this->assertOk([self::leaf('image', ['src' => '/storage/a.png', 'decorative' => true])]);
    }

    public function test_images_need_an_allowed_source(): void
    {
        $this->assertRejects(self::validate(self::raw([self::leaf('image', ['src' => '', 'alt' => 'x'])])), 'Choose an image');
        foreach (['javascript:alert(1)', 'data:image/png;base64,AAAA', '//evil.com/a.png', 'ftp://example.com/a.png'] as $src) {
            $this->assertRejects(self::validate(self::raw([self::leaf('image', ['src' => $src, 'alt' => 'x'])])), 'not allowed');
        }
        $this->assertInvalid([self::leaf('image', ['src' => 'x', 'alt' => 'x', 'width' => 0])]);
    }

    public function test_unsafe_button_links_are_rejected(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,<b>x</b>', " java\tscript:alert(1)", 'JAVASCRIPT:alert(1)', '//evil.com', ''] as $href) {
            $this->assertInvalid([self::leaf('button', ['label' => 'Go', 'href' => $href])], json_encode($href));
        }
        foreach (['/admissions', 'https://example.com/x', 'mailto:info@example.com', 'tel:+639171234567', '#top', 'page?x=1'] as $href) {
            $this->assertOk([self::leaf('button', ['label' => 'Go', 'href' => $href])], $href);
        }
        $this->assertRejects(self::validate(self::raw([self::leaf('button', ['label' => ' ', 'href' => '/'])])), 'label');
    }

    public function test_unsafe_card_links_and_images_are_rejected(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html;base64,AAAA', " java\tscript:alert(1)"] as $linkHref) {
            $this->assertInvalid([self::leaf('card', ['title' => 'T', 'linkLabel' => 'Go', 'linkHref' => $linkHref])], json_encode($linkHref));
        }
        $this->assertRejects(self::validate(self::raw([self::leaf('card', ['title' => 'T', 'imageUrl' => 'javascript:alert(1)'])])), 'not allowed');
        $this->assertRejects(self::validate(self::raw([self::leaf('card', ['title' => 'T', 'linkHref' => '/x'])])), 'label');
        $this->assertOk([self::leaf('card', ['title' => 'T', 'linkLabel' => 'Go', 'linkHref' => '/x'])]);
        $this->assertOk([self::leaf('card', ['title' => 'T'])]);
    }

    public function test_videos_are_youtube_vimeo_or_our_own_uploads(): void
    {
        $key = '0123456789abcdef0123456789abcdef';
        foreach (['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ', 'https://vimeo.com/123456789', "/storage/videos/{$key}.mp4", "/storage/videos/{$key}.webm"] as $url) {
            $this->assertOk([self::leaf('video', ['url' => $url, 'title' => 'Tour'])], $url);
        }
        foreach (['https://evil.com/watch?v=dQw4w9WgXcQ', 'https://youtube.com.evil.com/watch?v=aaaaaaaaaaa', 'javascript:alert(1)', '', "https://evil.com/storage/videos/{$key}.mp4", "/storage/videos/../{$key}.mp4", "/storage/images/{$key}.mp4", "/storage/videos/{$key}.mov"] as $url) {
            $this->assertRejects(self::validate(self::raw([self::leaf('video', ['url' => $url, 'title' => 'Tour'])])), 'YouTube or Vimeo');
        }
        $this->assertRejects(self::validate(self::raw([self::leaf('video', ['url' => 'https://youtu.be/dQw4w9WgXcQ', 'title' => ''])])), 'title');
    }

    public function test_the_image_link_defaults_to_none_and_follows_the_safe_link_rules(): void
    {
        $result = self::validate(self::raw([self::leaf('image', ['src' => '/a.png', 'alt' => 'A'])]));
        $this->assertSame('', $result['document']['blocks'][0]['props']['link']);

        foreach (['/posts', 'https://example.com/x', 'mailto:a@b.co', '#top'] as $link) {
            $this->assertOk([self::leaf('image', ['src' => '/a.png', 'alt' => 'A', 'link' => $link])], $link);
        }
        foreach (['javascript:alert(1)', 'data:text/html;base64,AAAA', " java\tscript:alert(1)", '//evil.com'] as $link) {
            $this->assertRejects(self::validate(self::raw([self::leaf('image', ['src' => '/a.png', 'alt' => 'A', 'link' => $link])])), 'valid link');
        }
        $this->assertRejects(self::validate(self::raw([self::leaf('image', ['src' => '/a.png', 'decorative' => true, 'link' => '/posts'])])), 'alt text');
    }

    public function test_albums_validate_images_alt_columns_and_the_image_limit(): void
    {
        $image = static fn (int $n): array => ['src' => "/storage/images/{$n}.webp", 'alt' => "Photo {$n}", 'caption' => $n % 2 ? 'Cap' : ''];
        $ok = self::validate(self::raw([self::leaf('album', ['images' => [$image(1), $image(2), $image(3)], 'columns' => 4])]));
        $this->assertTrue($ok['ok']);
        $this->assertSame(['images' => [$image(1), $image(2), $image(3)], 'columns' => 4], $ok['document']['blocks'][0]['props']);
        $this->assertSame(3, self::validate(self::raw([self::leaf('album', ['images' => [$image(1)]])]))['document']['blocks'][0]['props']['columns']);

        $this->assertRejects(self::validate(self::raw([self::leaf('album', ['images' => []])])), 'at least one image');
        $this->assertRejects(self::validate(self::raw([self::leaf('album', ['images' => [['src' => '/a.png', 'alt' => ' ', 'caption' => '']]])])), 'alt text');
        $this->assertRejects(self::validate(self::raw([self::leaf('album', ['images' => [['src' => '', 'alt' => 'A', 'caption' => '']]])])), 'choose an image');
        $this->assertRejects(self::validate(self::raw([self::leaf('album', ['images' => [['src' => 'javascript:alert(1)', 'alt' => 'A', 'caption' => '']]])])), 'not allowed');
        foreach ([1, 5, '3', null] as $columns) {
            $this->assertInvalid([self::leaf('album', ['images' => [$image(1)], 'columns' => $columns])], json_encode($columns));
        }
        $tooMany = array_map($image, range(0, Limits::ALBUM_IMAGES));
        $this->assertInvalid([self::leaf('album', ['images' => $tooMany])]);
        $this->assertOk([self::leaf('album', ['images' => array_slice($tooMany, 0, Limits::ALBUM_IMAGES)])]);
        $dirty = self::validate(self::raw([self::leaf('album', ['images' => [[...$image(1), 'onclick' => 'x']]])]));
        $this->assertStringNotContainsString('onclick', Schema::serialize($dirty['document']));
    }

    public function test_headings_are_level_2_to_4(): void
    {
        foreach ([2, 3, 4] as $level) {
            $this->assertOk([self::leaf('heading', ['text' => 'T', 'level' => $level])]);
        }
        foreach ([1, 5, 6, 0, '2', null] as $level) {
            $this->assertInvalid([self::leaf('heading', ['text' => 'T', 'level' => $level])], json_encode($level));
        }
        $this->assertOk([self::leaf('heading', ['text' => 'T'])]);
    }

    public function test_tables_are_rectangular_and_not_empty(): void
    {
        $this->assertRejects(self::validate(self::raw([self::leaf('table', ['rows' => [['a', 'b'], ['c']]])])), 'same number of columns');
        $this->assertRejects(self::validate(self::raw([self::leaf('table', ['rows' => []])])), 'at least one row');
        $this->assertOk([self::leaf('table', ['rows' => [['a', 'b'], ['c', 'd']]])]);
        $this->assertInvalid([self::leaf('table', ['rows' => [[]]])]);
        $this->assertInvalid([self::leaf('table', ['rows' => [array_fill(0, Limits::TABLE_COLUMNS + 1, 'x')]])]);
    }

    public function test_accordion_items_need_titles(): void
    {
        $this->assertRejects(self::validate(self::raw([self::leaf('accordion', ['items' => []])])), 'at least one item');
        $this->assertRejects(self::validate(self::raw([self::leaf('accordion', ['items' => [['title' => ' ', 'body' => 'x']]])])), 'title');
        $this->assertOk([self::leaf('accordion', ['items' => [['title' => 'Q', 'body' => 'A']]])]);
    }

    #[DataProvider('eventDates')]
    public function test_event_dates(array $props, bool $valid): void
    {
        $this->assertSame($valid, self::validate(self::raw([self::leaf('event', $props)]))['ok'], json_encode($props));
    }

    public static function eventDates(): array
    {
        return [
            [['start' => '2026-08-15'], true],
            [['start' => '2026-08-15T08:00'], true],
            [['start' => '2026-08-15T08:00', 'end' => '2026-08-15T10:00'], true],
            [['start' => '2026-08-15', 'end' => '2026-08-15'], true],
            [['start' => '2028-02-29'], true],
            [[], false],
            [['start' => ''], false],
            [['start' => '2026-02-30'], false],
            [['start' => '2027-02-29'], false],
            [['start' => '15-08-2026'], false],
            [['start' => '2026-08-15T25:00'], false],
            [['start' => '2026-08-15T08:60'], false],
            [['start' => '2026-08-15', 'end' => 'soon'], false],
        ];
    }

    public function test_the_end_of_an_event_cannot_precede_its_start(): void
    {
        $this->assertRejects(self::validate(self::raw([self::leaf('event', ['start' => '2026-08-15T10:00', 'end' => '2026-08-15T08:00'])])), 'end must be after');
    }

    public function test_limits(): void
    {
        $paragraphs = static fn (int $count): array => array_map(static fn (int $i): array => self::leaf('paragraph', ['text' => 'x'], "p{$i}"), range(0, $count - 1));
        $this->assertTrue(self::validate(self::raw($paragraphs(Limits::BLOCKS)))['ok']);
        $this->assertRejects(self::validate(self::raw($paragraphs(Limits::BLOCKS + 1))), 'at most '.Limits::BLOCKS.' elements');
        $row = ['id' => 'r', 'type' => 'row', 'props' => (object) [], 'children' => $paragraphs(Limits::BLOCKS)];
        $this->assertRejects(self::validate(self::raw([$row])), 'at most '.Limits::BLOCKS.' elements');

        $nested = static function (int $depth, int $level = 1) use (&$nested): array {
            return ['id' => "r{$level}", 'type' => 'row', 'props' => (object) [], 'children' => $level < $depth ? [$nested($depth, $level + 1)] : []];
        };
        $this->assertTrue(self::validate(self::raw([$nested(Limits::DEPTH)]))['ok']);
        $this->assertRejects(self::validate(self::raw([$nested(Limits::DEPTH + 1)])), 'nested at most '.Limits::DEPTH.' levels');

        $this->assertOk([self::leaf('paragraph', ['text' => str_repeat('x', Limits::TEXT)])]);
        $this->assertInvalid([self::leaf('paragraph', ['text' => str_repeat('x', Limits::TEXT + 1)])]);
        $this->assertInvalid([self::leaf('heading', ['text' => str_repeat('x', Limits::LABEL + 1)])]);
        $this->assertInvalid([self::leaf('list', ['items' => array_fill(0, Limits::LIST_ITEMS + 1, 'x')])]);
        $this->assertInvalid([self::leaf('badges', ['items' => array_fill(0, Limits::BADGE_ITEMS + 1, 'x')])]);
        $this->assertInvalid([self::leaf('markdown', ['source' => str_repeat('x', Limits::MARKDOWN + 1)])]);
    }

    public function test_string_limits_count_characters_not_bytes(): void
    {
        $this->assertOk([self::leaf('heading', ['text' => str_repeat('é', Limits::LABEL)])]);
        $this->assertOk([self::leaf('heading', ['text' => str_repeat('😀', Limits::LABEL)])]);
        $this->assertInvalid([self::leaf('heading', ['text' => str_repeat('😀', Limits::LABEL + 1)])]);
    }

    public function test_oversized_and_unparsable_stored_documents_are_rejected(): void
    {
        $this->assertRejects(Schema::parseBlockDocument(str_repeat('x', Limits::DOCUMENT_CHARS + 1)), 'too large');
        $this->assertRejects(Schema::parseBlockDocument('{not json'), 'not valid JSON');
        $this->assertRejects(Schema::parseBlockDocument('{"type":"sic-blocks","version":1,"blocks":"x"}'), 'not a valid block document');
    }

    public function test_a_stored_document_round_trips_with_whole_numbers_as_ints(): void
    {
        $stored = '{"type":"sic-blocks","version":1,"blocks":[{"id":"i1","type":"image","props":{"src":"/a/é.png","alt":"A \u0026 <b>","decorative":false,"caption":"","width":640.0,"height":480,"link":""}}]}';
        $result = Schema::parseBlockDocument($stored);

        $this->assertTrue($result['ok']);
        $this->assertSame(
            '{"type":"sic-blocks","version":1,"blocks":[{"id":"i1","type":"image","props":{"src":"/a/é.png","alt":"A & <b>","decorative":false,"caption":"","width":640,"height":480,"link":"","color":"","background":""}}]}',
            Schema::serialize($result['document']),
        );
    }
}
