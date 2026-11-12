<?php

namespace Tests\Unit\Storage;

use App\Storage\ImageVariant;
use App\Storage\Storage;
use Tests\TestCase;

class ImageVariantTest extends TestCase
{
    use UsesUploadsDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalUploads();
    }

    private function image(int $width): string
    {
        return $this->imageBytes($width, intdiv($width, 2), 'webp');
    }

    private function widthOf(string $key): int
    {
        return $this->sizeOf(Storage::read($key)->contents())[0];
    }

    public function test_variant_of_scales_down_to_640_px_wide_and_never_up(): void
    {
        $this->assertSame(640, $this->sizeOf(ImageVariant::variantOf($this->image(1600), 640))[0]);
        $this->assertSame(300, $this->sizeOf(ImageVariant::variantOf($this->image(300), 640))[0]);
    }

    public function test_backfill_creates_missing_variants_of_highlight_images_only_and_a_second_run_changes_nothing(): void
    {
        Storage::putBytes('posts/a.webp', $this->image(1600), 'image/webp');
        Storage::putBytes('posts/b.webp', $this->image(1200), 'image/webp');
        Storage::putBytes('posts/b.w640.webp', $this->image(640), 'image/webp');
        Storage::putBytes('posts/inline/c.webp', $this->image(1600), 'image/webp');
        Storage::putBytes('posts/broken.webp', 'not an image', 'image/webp');

        $first = ImageVariant::backfillVariants();
        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $first['existing']);
        $this->assertSame(['posts/broken.webp'], array_column($first['skipped'], 'key'));
        $this->assertNotNull(Storage::head('posts/a.w640.webp'));
        $this->assertNull(Storage::head('posts/inline/c.w640.webp'));

        $second = ImageVariant::backfillVariants();
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['existing']);
    }

    public function test_backfill_creates_the_960_px_variant_of_the_given_slide_photos_only(): void
    {
        Storage::putBytes('carousels/s.webp', $this->image(1920), 'image/webp');
        Storage::putBytes('carousels/layer.webp', $this->image(800), 'image/webp');

        $report = ImageVariant::backfillVariants(['carousels/s.webp']);
        $this->assertSame(1, $report['created']);
        $this->assertSame(960, $this->widthOf('carousels/s.w960.webp'));
        $this->assertNull(Storage::head('carousels/layer.w960.webp'));
    }

    public function test_backfill_creates_the_128_px_variant_of_every_department_logo_and_member_portrait_but_never_for_other_folders(): void
    {
        Storage::putBytes('departments/logo.webp', $this->image(512), 'image/webp');
        Storage::putBytes('members/face.webp', $this->image(480), 'image/webp');
        Storage::putBytes('downloads/scan.webp', $this->image(900), 'image/webp');

        $report = ImageVariant::backfillVariants();
        $this->assertSame(2, $report['created']);
        foreach (['departments/logo.w128.webp', 'members/face.w128.webp'] as $key) {
            $this->assertSame(128, $this->widthOf($key));
        }
        $this->assertNull(Storage::head('downloads/scan.w128.webp'));
    }
}
