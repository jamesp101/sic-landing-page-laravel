<?php

namespace Tests\Unit\Carousel;

use App\Carousel\Overlay;
use App\Carousel\OverlayMobile as M;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Port of carousel-overlay-mobile.test.ts (`resolveMobile` and its helpers); `fixtures/layout.json` comes from the TS implementation. */
class OverlayMobileTest extends TestCase
{
    private static int $counter = 0;

    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/layout.json'), true);
    }

    private static function make(string $type, array $over = []): array
    {
        return [...Overlay::defaultLayer($type), 'id' => 't'.(++self::$counter), ...$over];
    }

    private static function heading(string $text = 'Welcome to San Isidro College', array $over = []): array
    {
        return self::make('text', ['text' => $text, ...$over]);
    }

    private static function subtitle(string $text = 'A school that forms hearts and minds', array $over = []): array
    {
        return self::make('text', ['role' => 'subtitle', 'size' => 'md', 'font' => 'sans', 'text' => $text, 'y' => 55, ...$over]);
    }

    private static function button(array $over = []): array
    {
        return self::make('button', ['y' => 74, ...$over]);
    }

    private static function badge(array $over = []): array
    {
        return self::make('badge', ['y' => 26, ...$over]);
    }

    private static function logo(array $over = []): array
    {
        return self::make('image', ['src' => '/storage/carousels/a.webp', 'alt' => 'Logo', 'y' => 8, ...$over]);
    }

    private static function panel(array $over = []): array
    {
        return self::make('shape', $over);
    }

    /** @return list<array{layer: array, box: array}> */
    private static function visible(array $layers): array
    {
        $boxes = M::resolveMobile($layers);
        $items = [];
        foreach ($layers as $layer) {
            if (! $boxes[$layer['id']]['hidden']) {
                $items[] = ['layer' => $layer, 'box' => $boxes[$layer['id']]];
            }
        }

        return $items;
    }

    private function expectNoOverlap(array $items): void
    {
        foreach ($items as $i => $a) {
            foreach (array_slice($items, $i + 1) as $b) {
                $apart = $a['box']['x'] + $a['box']['w'] <= $b['box']['x'] + 1e-6
                    || $b['box']['x'] + $b['box']['w'] <= $a['box']['x'] + 1e-6
                    || $a['box']['y'] + $a['box']['h'] <= $b['box']['y'] + 1e-6
                    || $b['box']['y'] + $b['box']['h'] <= $a['box']['y'] + 1e-6;
                $this->assertTrue($apart, "{$a['layer']['id']} overlaps {$b['layer']['id']}");
            }
        }
    }

    private function expectInsideFrame(array $items): void
    {
        foreach ($items as ['layer' => $layer, 'box' => $box]) {
            $this->assertGreaterThanOrEqual(0, $box['x'], $layer['id']);
            $this->assertGreaterThanOrEqual(0, $box['y'], $layer['id']);
            $this->assertLessThanOrEqual(100 + 1e-6, $box['x'] + $box['w'], $layer['id']);
            $this->assertLessThanOrEqual(100 - M::MOBILE_BOTTOM + 0.01, $box['y'] + $box['h'], $layer['id']);
        }
    }

    // ---- TS fixtures -------------------------------------------------------------------------------------------

    public static function layerSets(): iterable
    {
        foreach (array_keys(self::fixture()['sets']) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('layerSets')]
    public function test_boxes_match_the_ts_implementation(string $name): void
    {
        $set = self::fixture()['sets'][$name];
        $boxes = M::resolveMobile($set['layers']);
        $this->assertCount(count($set['boxes']), $boxes);
        foreach ($set['boxes'] as [$id, $box]) {
            $this->assertEquals($box, $boxes[$id], "{$name}: box of {$id}");
        }
        // Same insertion order as the TS Map.
        $this->assertSame(array_map(fn (array $b) => (string) $b[0], $set['boxes']), array_map('strval', array_keys($boxes)));
    }

    public function test_count_lines_matches_the_ts_implementation(): void
    {
        foreach (self::fixture()['countLines'] as $case) {
            $this->assertSame($case['out'], M::countLines($case['text'], $case['cap']), json_encode($case));
        }
    }

    public function test_slide_focus_matches_the_ts_implementation(): void
    {
        [$centred, $set] = self::fixture()['focus'];
        $this->assertEquals($centred, M::slideFocus(null));
        $this->assertEquals($set, M::slideFocus(['focus' => ['x' => 20, 'y' => 30]]));
    }

    // ---- ported cases ------------------------------------------------------------------------------------------

    public function test_count_lines_wraps_words_greedily(): void
    {
        $this->assertSame(2, M::countLines('aaa bbb ccc', 7));
        $this->assertSame(1, M::countLines('aaa bbb ccc', 11));
        $this->assertSame(2, M::countLines("one\ntwo", 20));
    }

    public function test_count_lines_breaks_words_longer_than_a_line(): void
    {
        $this->assertSame(3, M::countLines(str_repeat('a', 25), 10));
        $this->assertSame(4, M::countLines('hi '.str_repeat('a', 25), 10));
    }

    public function test_every_phone_step_is_at_least_12_px_on_a_320_px_phone(): void
    {
        foreach (M::MOBILE_TYPE_SCALE as $steps) {
            foreach ($steps as $cqw) {
                $this->assertGreaterThanOrEqual(12, $cqw * 320 / 100);
            }
        }
        $this->assertGreaterThanOrEqual(12, M::MIN_CQW * 3.2);
    }

    public function test_desktop_sizes_step_down_a_notch_and_an_override_wins(): void
    {
        $this->assertSame('md', M::stepDown('lg'));
        $this->assertSame('sm', M::stepDown('md'));
        $this->assertSame('sm', M::stepDown('sm'));
        $this->assertSame('md', M::mobileSize(self::heading('x', ['size' => 'lg'])));
        $this->assertSame('lg', M::mobileSize(self::heading('x', ['size' => 'lg', 'mobile' => ['size' => 'lg']])));
        $this->assertNull(M::mobileSize(self::panel()));
    }

    public function test_automatic_stack_keeps_reading_order_full_width_no_overlaps_inside_the_frame(): void
    {
        $layers = [self::badge(), self::heading(), self::subtitle(), self::button()];
        $items = self::visible($layers);
        $this->assertCount(4, $items);
        $order = array_map(fn (array $i) => $i['layer']['id'], Overlay::readingOrder($layers));
        usort($items, fn (array $a, array $b) => $a['box']['y'] <=> $b['box']['y']);
        $this->assertSame($order, array_map(fn (array $i) => $i['layer']['id'], $items));
        foreach ($items as ['layer' => $layer, 'box' => $box]) {
            $this->assertTrue($box['auto']);
            $this->assertSame(M::MOBILE_MARGIN, $box['x']);
            if ($layer['type'] === 'text') {
                $this->assertSame(100 - 2 * M::MOBILE_MARGIN, $box['w']);
            }
        }
        $this->expectNoOverlap($items);
        $this->expectInsideFrame($items);
    }

    public function test_the_stack_is_bottom_aligned_above_the_controls(): void
    {
        $items = self::visible([self::badge(), self::heading(), self::subtitle(), self::button()]);
        $bottom = max(array_map(fn (array $i) => $i['box']['y'] + $i['box']['h'], $items));
        $this->assertEqualsWithDelta(100 - M::MOBILE_BOTTOM, $bottom, 0.05);
    }

    public function test_text_sizes_are_one_notch_below_desktop(): void
    {
        $items = self::visible([self::heading('Hi', ['size' => 'lg'])]);
        $this->assertSame(M::MOBILE_TYPE_SCALE['heading']['md'], $items[0]['box']['fs']);
    }

    public function test_hidden_layers_and_desktop_panels_are_skipped(): void
    {
        $hidden = self::subtitle('x', ['mobile' => ['hidden' => true]]);
        $layers = [self::panel(), self::heading(), $hidden, self::button()];
        $boxes = M::resolveMobile($layers);
        $this->assertTrue($boxes[$layers[0]['id']]['hidden']);
        $this->assertTrue($boxes[$hidden['id']]['hidden']);
        $items = self::visible($layers);
        $this->assertSame(['text', 'button'], array_map(fn (array $i) => $i['layer']['type'], $items));
        $this->expectNoOverlap($items);
    }

    public function test_hiding_a_layer_frees_its_space(): void
    {
        $layers = [self::badge(), self::heading(), self::subtitle(), self::button()];
        $before = M::resolveMobile($layers);
        $after = M::resolveMobile(array_map(fn (array $l) => $l['type'] === 'badge' ? [...$l, 'mobile' => ['hidden' => true]] : $l, $layers));
        $this->assertGreaterThan($before[$layers[1]['id']]['y'], $after[$layers[1]['id']]['y']);
    }

    public function test_a_logo_keeps_its_aspect_ratio_and_stays_narrow(): void
    {
        $items = self::visible([self::logo(['w' => 20, 'h' => 18]), self::heading()]);
        $image = array_values(array_filter($items, fn (array $i) => $i['layer']['type'] === 'image'))[0]['box'];
        $this->assertLessThanOrEqual(40, $image['w']);
        $this->assertEqualsWithDelta((20 * 16) / (18 * 9), $image['w'] / $image['h'] / M::FRAME_H_PER_W, 0.05);
        $this->expectNoOverlap($items);
    }

    public function test_long_content_shrinks_spacing_and_sizes_to_fit(): void
    {
        $long = str_repeat('Enrolment for the new school year is now open for all grade levels and strands. ', 2);
        $items = self::visible([
            self::badge(),
            self::heading('Join the San Isidro College family this school year and grow with us', ['size' => 'lg']),
            self::subtitle($long, ['y' => 50, 'size' => 'lg']),
            self::button(),
            self::button(['label' => 'Apply for admission today', 'y' => 80]),
        ]);
        $this->expectNoOverlap($items);
        $this->expectInsideFrame($items);
        foreach ($items as ['box' => $box]) {
            if ($box['fs'] !== null) {
                $this->assertGreaterThanOrEqual(M::MIN_CQW, $box['fs']);
            }
        }
    }

    public function test_the_stack_is_independent_of_array_order(): void
    {
        $layers = [self::badge(), self::heading(), self::subtitle(), self::button()];
        $forward = M::resolveMobile($layers);
        $reversed = M::resolveMobile(array_reverse($layers));
        foreach ($layers as $layer) {
            $this->assertEquals($forward[$layer['id']], $reversed[$layer['id']]);
        }
    }

    public function test_a_placed_layer_keeps_its_box_and_is_not_auto(): void
    {
        $placed = self::heading('Hi', ['mobile' => ['x' => 10, 'y' => 20, 'w' => 70]]);
        $box = M::resolveMobile([$placed])[$placed['id']];
        $this->assertEquals(['x' => 10, 'y' => 20, 'w' => 70, 'auto' => false, 'hidden' => false, 'autoW' => false], array_intersect_key($box, array_flip(['x', 'y', 'w', 'auto', 'hidden', 'autoW'])));
    }

    public function test_a_size_only_override_keeps_the_layer_automatic_and_its_size_is_used(): void
    {
        $sized = self::heading('Hi', ['size' => 'sm', 'mobile' => ['size' => 'lg']]);
        $box = M::resolveMobile([$sized])[$sized['id']];
        $this->assertTrue($box['auto']);
        $this->assertSame(M::MOBILE_TYPE_SCALE['heading']['lg'], $box['fs']);
    }

    public function test_stacked_layers_move_below_a_hand_placed_one(): void
    {
        $fixed = self::panel(['mobile' => ['x' => 0, 'y' => 60, 'w' => 100, 'h' => 20]]);
        $items = self::visible([self::heading('Title'), $fixed, self::button()]);
        $this->assertFalse(array_values(array_filter($items, fn (array $i) => $i['layer']['id'] === $fixed['id']))[0]['box']['auto']);
        $this->expectNoOverlap($items);
    }

    public function test_hand_placed_layers_do_not_move_when_others_change(): void
    {
        $placed = self::heading('Hi', ['mobile' => ['x' => 10, 'y' => 20, 'w' => 70]]);
        $a = M::resolveMobile([$placed, self::button()])[$placed['id']];
        $b = M::resolveMobile([$placed, self::button(), self::badge()])[$placed['id']];
        $this->assertEquals($a, $b);
    }

    public function test_a_layer_hidden_on_phones_is_hidden_even_when_placed(): void
    {
        $layer = self::heading('Hi', ['mobile' => ['x' => 10, 'y' => 20, 'w' => 70, 'hidden' => true]]);
        $this->assertTrue(M::resolveMobile([$layer])[$layer['id']]['hidden']);
    }

    public function test_an_empty_override_object_counts_as_no_override(): void
    {
        $layer = self::heading('Hi', ['mobile' => new \stdClass]);
        $this->assertTrue(M::resolveMobile([$layer])[$layer['id']]['auto']);
    }

    public function test_frame_constants_leave_room_for_the_stack(): void
    {
        $this->assertLessThan(40, M::MOBILE_TOP + M::MOBILE_BOTTOM);
    }
}
