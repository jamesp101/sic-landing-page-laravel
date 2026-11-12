<?php

namespace Tests\Unit\Storage;

use App\Storage\Variants;
use PHPUnit\Framework\TestCase;

class VariantsTest extends TestCase
{
    public function test_it_derives_the_variant_of_a_highlight_image_only(): void
    {
        $this->assertSame('posts/abc.w640.webp', Variants::variantKeyOf('posts/abc.webp', 640));
        $this->assertSame('posts/abc.w640.webp', Variants::variantKeyOf('posts/abc.JPG', 640));
        foreach ([['posts/abc.webp', 960], ['posts/inline/abc.webp', 640], ['posts/abc.pdf', 640], ['posts/abc.w640.webp', 640]] as [$key, $width]) {
            $this->assertNull(Variants::variantKeyOf($key, $width), "{$key} {$width}");
        }
        $this->assertSame(['posts/abc.w640.webp'], Variants::variantKeysOf('posts/abc.webp'));
    }

    public function test_it_gives_a_slide_photo_its_own_width(): void
    {
        $this->assertSame('carousels/abc.w960.webp', Variants::variantKeyOf('carousels/abc.webp', 960));
        $this->assertNull(Variants::variantKeyOf('carousels/abc.webp', 640));
        $this->assertSame([], Variants::variantKeysOf('downloads/abc.webp'));
        $this->assertTrue(Variants::isVariantKey('carousels/abc.w960.webp'));
        $this->assertSame('carousels/abc', Variants::variantBase('carousels/abc.w960.webp'));
        $this->assertSame('carousels/abc.webp', Variants::originalCandidates('carousels/abc.w960.webp')[0]);
    }

    public function test_it_gives_department_logos_and_member_portraits_a_128_px_variant_and_nothing_else_under_those_prefixes(): void
    {
        $this->assertSame(128, Variants::THUMB_WIDTH);
        $this->assertSame('departments/abc.w128.webp', Variants::variantKeyOf('departments/abc.webp', 128));
        $this->assertSame('members/abc.w128.webp', Variants::variantKeyOf('members/abc.PNG', 128));
        $this->assertNull(Variants::variantKeyOf('members/abc.webp', 640));
        $this->assertNull(Variants::variantKeyOf('members/nested/abc.webp', 128));
        $this->assertSame([], Variants::variantWidths('members/abc.w128.webp'));
        $this->assertTrue(Variants::isVariantKey('departments/abc.w128.webp'));
        $this->assertSame('members/abc', Variants::variantBase('members/abc.w128.webp'));
        $this->assertSame('members/abc.webp', Variants::originalCandidates('members/abc.w128.webp')[0]);
    }

    public function test_it_looks_for_an_original_only_behind_a_variant_its_kind_has_and_tries_a_bounded_number_of_keys(): void
    {
        foreach (['downloads/abc.w640.webp', 'posts/inline/abc.w640.webp', 'posts/abc.w960.webp', 'carousels/abc.w128.webp', 'posts/abc.webp', '../posts/abc.w640.webp'] as $key) {
            $this->assertSame([], Variants::originalCandidates($key), $key);
        }
        $candidates = Variants::originalCandidates('posts/abc.w640.webp');
        $this->assertGreaterThan(0, count($candidates));
        $this->assertLessThanOrEqual(4, count($candidates));
        $this->assertSame(['posts/abc.webp', 'posts/abc.jpg'], array_slice($candidates, 0, 2));
    }

    public function test_it_recognises_variants_and_finds_their_originals(): void
    {
        $this->assertTrue(Variants::isVariantKey('posts/abc.w640.webp'));
        $this->assertFalse(Variants::isVariantKey('posts/abc.webp'));
        $this->assertSame('posts/abc', Variants::variantBase('posts/abc.w640.webp'));
        $this->assertSame('posts/abc', Variants::variantBase('posts/abc.webp'));
        $this->assertSame('posts/abc.webp', Variants::originalCandidates('posts/abc.w640.webp')[0]);
        $this->assertContains('posts/abc.jpg', Variants::originalCandidates('posts/abc.w640.webp'));
    }
}
