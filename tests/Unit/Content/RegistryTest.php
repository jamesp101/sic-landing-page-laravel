<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Content\Blocks\PlainText;
use App\Content\Blocks\Registry;
use App\Content\Blocks\Renderer;
use App\Content\Blocks\Schema;
use App\Content\Blocks\Z;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every registered Block type through defaults, example props, hostile-string escaping and plain text. */
final class RegistryTest extends TestCase
{
    private const HOSTILE = '<script>alert(1)</script><img src=x onerror=alert(1)>"\'&';

    /** Per type: a valid example (`null` = the defaults already validate) and the words plain text must contain. */
    private const FIXTURES = [
        'section' => [null, []],
        'row' => [null, []],
        'grid' => [null, []],
        'columns' => [null, []],
        'spacer' => [null, []],
        'divider' => [null, []],
        'heading' => [['text' => 'Welcome heading', 'level' => 2, 'align' => 'inherit'], ['Welcome heading']],
        'paragraph' => [['text' => 'Some **bold** words', 'size' => 'base', 'tone' => 'default', 'align' => 'inherit'], ['Some bold words']],
        'list' => [['ordered' => false, 'items' => ['One', 'Two']], ['One Two']],
        'quote' => [['text' => 'Quoted line', 'cite' => 'Someone'], ['Quoted line']],
        'button' => [['label' => 'Apply', 'href' => '/apply', 'variant' => 'primary', 'align' => 'left'], []],
        'badges' => [['items' => ['Red', 'Blue']], ['Red, Blue']],
        'card' => [['title' => 'Card title', 'body' => 'Card body', 'imageUrl' => '', 'imageAlt' => '', 'linkLabel' => '', 'linkHref' => ''], ['Card title Card body']],
        'image' => [['src' => '/storage/a.webp', 'alt' => 'A photo', 'decorative' => false, 'caption' => 'Cap', 'width' => null, 'height' => null, 'link' => ''], []],
        'album' => [['images' => [['src' => '/storage/a.webp', 'alt' => 'Alt one', 'caption' => 'Cap one']], 'columns' => 3], ['Alt one Cap one']],
        'table' => [['header' => true, 'caption' => 'Caption', 'rows' => [['A1', 'B1'], ['A2', 'B2']]], ['A1 B1 A2 B2']],
        'accordion' => [['items' => [['title' => 'Q one', 'body' => 'A one']]], ['Q one A one']],
        'callout' => [['tone' => 'info', 'title' => 'Notice', 'text' => 'Details'], ['Notice Details']],
        'stats' => [['items' => [['value' => '100+', 'label' => 'Students', 'hint' => 'since 1950']]], ['100+ Students']],
        'video' => [['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'A video', 'caption' => ''], []],
        'event' => [['title' => 'Open day', 'start' => '2026-03-05T09:00', 'end' => '', 'location' => 'Main hall', 'note' => 'Bring ID'], ['Open day', 'Main hall', 'Bring ID']],
        'markdown' => [['source' => '**Bold** md text'], ['Bold md text']],
    ];

    public static function types(): array
    {
        return array_map(static fn (string $type): array => [$type], Registry::types());
    }

    /** Round trip through JSON, like stored content: lists stay lists, props become objects. */
    private static function decoded(array $value): mixed
    {
        return json_decode(json_encode($value), false);
    }

    private static function block(string $type, array $props): array
    {
        $block = ['id' => 'b1', 'type' => $type, 'props' => $props];
        if (Registry::isContainerType($type)) {
            $block['children'] = [];
        }

        return $block;
    }

    private static function sample(string $type): array
    {
        return self::block($type, self::FIXTURES[$type][0] ?? Registry::get($type)->defaults());
    }

    private static function documentOf(array $blocks): array
    {
        return ['type' => 'sic-blocks', 'version' => 1, 'blocks' => $blocks];
    }

    private static function isValid(array $blocks): bool
    {
        return Schema::validateDocument(self::decoded(self::documentOf($blocks)))['ok'];
    }

    public function test_the_registry_lists_the_fixture_types_in_palette_order(): void
    {
        $listed = json_decode((string) file_get_contents(__DIR__.'/fixtures/block-types.json'), true, 512, JSON_THROW_ON_ERROR)['types'];

        $this->assertSame($listed, Registry::types());
        $this->assertSame($listed, array_keys(self::FIXTURES));
    }

    #[DataProvider('types')]
    public function test_defaults_fit_the_schema_unchanged(string $type): void
    {
        $kind = Registry::get($type);
        $issues = [];
        $parsed = Z::parse($kind->schema(), self::decoded($kind->defaults()), [], $issues);

        $this->assertSame([], $issues);
        $this->assertSame($kind->defaults(), $parsed);
    }

    #[DataProvider('types')]
    public function test_example_props_validate_and_are_kept_as_stored(string $type): void
    {
        $result = Schema::validateDocument(self::decoded(self::documentOf([self::sample($type)])));

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame(self::sample($type)['props'], $result['document']['blocks'][0]['props']);
    }

    #[DataProvider('types')]
    public function test_it_is_a_container_exactly_when_a_fresh_block_has_children(string $type): void
    {
        $this->assertSame(Registry::get($type)->container(), array_key_exists('children', self::sample($type)));
    }

    #[DataProvider('types')]
    public function test_renders_in_the_editor_and_publicly_unless_it_is_an_empty_container(string $type): void
    {
        $example = self::sample($type);

        $this->assertStringContainsString('data-block-type="'.$type.'"', Renderer::renderBlocks([$example], ['editor' => true]));
        if (! Registry::get($type)->container()) {
            $this->assertNotSame('', Renderer::renderBlocks([$example]));
        }
    }

    #[DataProvider('types')]
    public function test_hostile_strings_are_escaped_in_both_renderings(string $type): void
    {
        [$block, $replaced] = $this->hostile($type);

        foreach ([false, true] as $editor) {
            $html = Renderer::renderBlocks([$block], ['editor' => $editor]);
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringNotContainsString('"<', $html);
            if ($replaced > 0 && $html !== '') {
                $this->assertStringContainsString('&lt;script&gt;', $html);
            }
        }
    }

    #[DataProvider('types')]
    public function test_plain_text_comes_from_the_stored_json(string $type): void
    {
        $text = PlainText::blocksToPlainText(Schema::serialize(self::documentOf([self::sample($type)])));
        $expected = self::FIXTURES[$type][1];

        if ($expected === []) {
            $this->assertSame('', $text);
        }
        foreach ($expected as $part) {
            $this->assertStringContainsString($part, $text);
        }
    }

    public function test_a_type_the_registry_does_not_know_contributes_no_text(): void
    {
        $json = json_encode(['type' => 'sic-blocks', 'version' => 1, 'blocks' => [['id' => 'a', 'type' => 'nope', 'props' => (object) []]]]);

        $this->assertSame('', PlainText::blocksToPlainText($json));
    }

    public function test_plain_text_tolerates_any_stored_shape(): void
    {
        $this->assertSame('', PlainText::blocksToPlainText('not json'));
        $this->assertSame('', PlainText::blocksToPlainText('[1,2]'));
        $this->assertSame('a b', PlainText::blocksToPlainText('{"blocks":[{"type":"heading","props":{"text":"a"},"children":[{"type":"heading","props":{"text":"b"}}]},5,null,{"type":"heading","props":"x"}]}'));
    }

    /** The block's example props with every string replaced by hostile markup wherever that still validates. */
    private function hostile(string $type): array
    {
        $props = self::sample($type)['props'];
        $replaced = 0;
        foreach (self::stringPaths($props) as $path) {
            $candidate = self::withValueAt($props, $path, self::HOSTILE);
            if (self::isValid([self::block($type, $candidate)])) {
                $props = $candidate;
                $replaced++;
            }
        }

        return [self::block($type, $props), $replaced];
    }

    /** @return list<list<string|int>> */
    private static function stringPaths(mixed $value, array $path = []): array
    {
        if (is_string($value)) {
            return [$path];
        }
        if (! is_array($value)) {
            return [];
        }
        $paths = [];
        foreach ($value as $key => $child) {
            array_push($paths, ...self::stringPaths($child, [...$path, $key]));
        }

        return $paths;
    }

    private static function withValueAt(mixed $value, array $path, string $next): mixed
    {
        if ($path === []) {
            return $next;
        }
        $key = $path[0];
        $value[$key] = self::withValueAt($value[$key], array_slice($path, 1), $next);

        return $value;
    }
}
