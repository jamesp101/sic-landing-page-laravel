<?php

namespace Tests\Unit\Admin\Core;

use App\Admin\Slug;
use PHPUnit\Framework\TestCase;

class SlugTest extends TestCase
{
    private function existsIn(array $taken): \Closure
    {
        return fn (string $slug) => in_array($slug, $taken, true);
    }

    public function test_slugify(): void
    {
        $this->assertSame('enrollment-details-2026', Slug::slugify('Enrollment Details 2026'));
        $this->assertSame('hello-world-again', Slug::slugify('  Hello,   World!! _ again '));
        $this->assertSame('cafe-senor', Slug::slugify('Café Señor'));
        $this->assertSame('', Slug::slugify('!!! ???'));
    }

    public function test_a_zero_is_a_usable_slug_and_only_combining_accents_are_stripped(): void
    {
        $this->assertSame('0', Slug::uniqueSlug('0', $this->existsIn([])));
        $this->assertSame('0-1', Slug::uniqueSlug('0', $this->existsIn(['0'])));
        $this->assertSame('a', Slug::slugify("a\u{0301}"));
    }

    public function test_unique_slug_appends_a_counter(): void
    {
        $this->assertSame('my-post', Slug::uniqueSlug('My Post', $this->existsIn([])));
        $this->assertSame('my-post-1', Slug::uniqueSlug('My Post', $this->existsIn(['my-post'])));
        $this->assertSame('my-post-3', Slug::uniqueSlug('My Post', $this->existsIn(['my-post', 'my-post-1', 'my-post-2'])));
    }

    public function test_unique_slug_fallbacks(): void
    {
        $this->assertSame('post', Slug::uniqueSlug('???', $this->existsIn([])));
        $this->assertSame('post-1', Slug::uniqueSlug('???', $this->existsIn(['post'])));
        $this->assertSame('department', Slug::uniqueSlug('???', $this->existsIn([]), 'department'));
    }

    public function test_numbered_slug_stays_within_the_255_character_column(): void
    {
        $long = str_repeat('word ', 60).'end';
        $free = Slug::uniqueSlug($long, $this->existsIn([]));
        $this->assertLessThanOrEqual(240, strlen($free));
        $this->assertStringEndsNotWith('-', $free);
        $numbered = Slug::uniqueSlug($long, $this->existsIn([$free]));
        $this->assertSame("{$free}-1", $numbered);
        $this->assertLessThanOrEqual(255, strlen($numbered));
    }
}
