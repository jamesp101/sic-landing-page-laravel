<?php

namespace Tests\Unit\Carousel;

use App\Carousel\Layout;
use App\Carousel\Overlay;
use App\Carousel\OverlayMobile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Port of carousel-layout.test.ts (the CSS-agreement and DOM-measuring parts stay with the client module). */
class LayoutTest extends TestCase
{
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/layout.json'), true);
    }

    public static function layerSets(): iterable
    {
        foreach (array_keys(self::fixture()['sets']) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('layerSets')]
    public function test_layer_presentations_match_the_ts_implementation(string $name): void
    {
        $set = self::fixture()['sets'][$name];
        $layers = array_column($set['layers'], null, 'id');
        $this->assertCount(count($layers) * 3, $set['presentations']);
        foreach ($set['presentations'] as $case) {
            $this->assertSame(
                $case['out'],
                Layout::layerPresentation($layers[$case['id']], $case['z'], $case['box'], $case['mode']),
                "{$name}: {$case['id']} {$case['mode']}",
            );
        }
    }

    public function test_hero_image_sizes_match_the_ts_implementation(): void
    {
        $fixture = self::fixture()['sizes'];
        $this->assertSame($fixture['tall'], Layout::heroImageSizes(true));
        $this->assertSame($fixture['short'], Layout::heroImageSizes(false));
        $this->assertSame($fixture['width'], ['tall' => Layout::heroPhoneImageWidth(true), 'short' => Layout::heroPhoneImageWidth(false)]);
    }

    public function test_a_phone_hero_draws_the_photo_as_wide_as_its_height_requires(): void
    {
        $this->assertSame(889, Layout::heroPhoneImageWidth(true));
        $this->assertSame(712, Layout::heroPhoneImageWidth(false));
        $this->assertSame('(max-width: 39.99rem) 889px, 100vw', Layout::heroImageSizes(true));
        $this->assertSame('(max-width: 39.99rem) 712px, 100vw', Layout::heroImageSizes(false));
    }

    private function heading(): array
    {
        return [...Overlay::defaultLayer('text'), 'id' => 'h', 'x' => 10, 'y' => 20, 'w' => 50, 'rotation' => 5, 'opacity' => 80, 'color' => 'navy'];
    }

    private function stacked(array $over = []): array
    {
        return ['x' => 6, 'y' => 40, 'w' => 88, 'h' => 10, 'autoW' => true, 'autoH' => true, 'fs' => 7, 'hidden' => false, 'auto' => true, ...$over];
    }

    public function test_public_layers_sit_at_their_desktop_box_and_carry_the_phone_box_as_custom_properties(): void
    {
        $look = Layout::layerPresentation($this->heading(), 3, $this->stacked(), 'public');
        $this->assertSame('z-index:3;left:10%;top:20%;width:50%;opacity:0.8;transform:rotate(5deg);--fs:'.Overlay::TYPE_SCALE['heading']['lg'].';--mx:6%;--my:40%;--mw:auto;--mh:auto;--mfs:7', $look['style']);
        $this->assertSame('sl-m sl-mauto', $look['phoneClass']);
        $this->assertSame('text-brand-800 sl-onphoto', $look['textColor']);
    }

    public function test_a_hand_placed_layer_keeps_its_dark_colour_on_phones_and_a_hidden_one_is_removed(): void
    {
        $look = Layout::layerPresentation($this->heading(), 1, $this->stacked(['auto' => false]), 'public');
        $this->assertSame(['sl-m ', 'text-brand-800'], [$look['phoneClass'], $look['textColor']]);
        $this->assertSame('sl-mhide', Layout::layerPresentation($this->heading(), 1, $this->stacked(['hidden' => true]), 'public')['phoneClass']);
    }

    public function test_the_desktop_preview_ignores_the_phone_box(): void
    {
        $look = Layout::layerPresentation($this->heading(), 1, $this->stacked(), 'desktop');
        $this->assertStringNotContainsString('--mx', $look['style']);
        $this->assertStringContainsString('left:10%', $look['style']);
        $this->assertSame(['', 'text-brand-800'], [$look['phoneClass'], $look['textColor']]);
    }

    public function test_the_phone_preview_applies_the_phone_box_directly(): void
    {
        $look = Layout::layerPresentation($this->heading(), 1, $this->stacked(), 'phone');
        $this->assertSame('z-index:1;left:6%;top:40%;--fs:7', $look['style']);
        $this->assertSame('text-white', $look['textColor']);
        $ghost = Layout::layerPresentation($this->heading(), 1, $this->stacked(['hidden' => true]), 'phone');
        $this->assertStringContainsString('opacity:0.3', $ghost['style']);
        $this->assertStringContainsString('outline-dashed', $ghost['ghostClass']);
    }

    public function test_an_automatic_phone_layout_resolves_the_same_box_in_preview_and_public(): void
    {
        $heading = $this->heading();
        $box = OverlayMobile::resolveMobile([$heading])['h'];
        $this->assertStringContainsString("left:{$box['x']}%", Layout::layerPresentation($heading, 1, $box, 'phone')['style']);
        $this->assertStringContainsString("--mx:{$box['x']}%", Layout::layerPresentation($heading, 1, $box, 'public')['style']);
    }
}
