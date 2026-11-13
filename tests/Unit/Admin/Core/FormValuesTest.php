<?php

namespace Tests\Unit\Admin\Core;

use App\Admin\FormValues;
use App\Models\HeroSection;
use App\Models\Post;
use Tests\TestCase;

/** The edit form islands declare the values they start from (`PostValues`, `HeroValues`); the keys must be the mapper's keys. */
class FormValuesTest extends TestCase
{
    /** Keys of `interface <name> { … }` declared in an island. */
    private function interfaceKeys(string $island, string $name): array
    {
        $source = (string) file_get_contents(base_path("resources/js/components/admin/{$island}.svelte"));
        $this->assertSame(1, preg_match("/interface {$name} \\{(.*?)\\n    \\}/s", $source, $body), "{$name} not found in {$island}");
        preg_match_all('/^\s+(\w+)\??:/m', $body[1], $keys);

        return $keys[1];
    }

    private function assertSameKeys(array $expected, array $actual): void
    {
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_post_values_have_the_keys_of_the_post_form_island(): void
    {
        $post = (new Post)->forceFill(['id' => 3, 'title' => 'T', 'slug' => 't', 'content' => 'C', 'type' => 'event']);

        $this->assertSameKeys($this->interfaceKeys('posts/PostForm', 'PostValues'), array_keys(FormValues::post($post)));
    }

    public function test_hero_values_have_the_keys_of_the_hero_section_form_island(): void
    {
        $section = (new HeroSection)->forceFill(['id' => 3, 'content' => 'C', 'order' => 1, 'is_active' => true]);

        $this->assertSameKeys($this->interfaceKeys('hero-sections/HeroSectionForm', 'HeroValues'), array_keys(FormValues::heroSection($section)));
    }

    public function test_post_values_format_instants_as_iso_strings(): void
    {
        $post = (new Post)->forceFill([
            'id' => 3, 'title' => 'T', 'slug' => 't', 'content' => 'C', 'type' => 'event',
            'start_date' => '2026-05-01 08:30:00', 'end_date' => null, 'is_featured' => true, 'hide_author' => false,
        ]);

        $values = FormValues::post($post);
        $this->assertSame('2026-05-01T08:30:00.000000Z', $values['startDate']);
        $this->assertNull($values['endDate']);
        $this->assertNull($values['highlightImageUrl']);
    }

    public function test_a_hero_section_without_a_title_has_an_empty_one(): void
    {
        $section = (new HeroSection)->forceFill(['id' => 3, 'title' => null, 'content' => 'C', 'order' => 1, 'is_active' => true]);

        $this->assertSame('', FormValues::heroSection($section)['title']);
    }
}
