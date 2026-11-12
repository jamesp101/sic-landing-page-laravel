<?php

namespace Tests\Feature\Storage;

use App\Storage\ImageIntake;
use App\Storage\Storage;
use App\Support\RequestError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\Storage\UsesUploadsDisk;

class ImageIntakeTest extends TestCase
{
    use RefreshDatabase;
    use UsesUploadsDisk;

    private int $nextUser = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalUploads();
    }

    /** Each case uses its own user so upload quotas never leak between cases. */
    private function newUser(): int
    {
        return $this->nextUser++;
    }

    private function code(callable $call): string
    {
        try {
            $call();
        } catch (RequestError $error) {
            return $error->errorCode;
        }
        $this->fail('expected a rejection');
    }

    public function test_it_stores_a_png_content_image_as_webp_under_posts_inline_and_serves_it_at_the_returned_url(): void
    {
        ['key' => $key, 'url' => $url] = ImageIntake::acceptImage($this->newUser(), 'content', $this->upload($this->imageBytes(40, 30), 'photo.png', 'image/png'));
        $this->assertMatchesRegularExpression('#^posts/inline/.+\.webp$#', $key);
        $this->assertSame("/storage/{$key}", $url);
        $this->assertSame([40, 30, IMAGETYPE_WEBP], $this->sizeOf(Storage::read($key)->contents()));
    }

    public function test_it_stores_a_png_layer_image_as_webp_under_carousels(): void
    {
        ['key' => $key, 'url' => $url] = ImageIntake::acceptImage($this->newUser(), 'layer', $this->upload($this->imageBytes(), 'layer.png', 'image/png'));
        $this->assertMatchesRegularExpression('#^carousels/.+\.webp$#', $key);
        $this->assertSame("/storage/{$key}", $url);
        $this->assertSame(IMAGETYPE_WEBP, $this->sizeOf(Storage::read($key)->contents())[2]);
    }

    public function test_it_rejects_an_svg_declared_as_png_and_stores_nothing(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';
        $this->assertSame(RequestError::BAD_REQUEST, $this->code(fn () => ImageIntake::acceptImage($this->newUser(), 'content', $this->upload($svg, 'logo.png', 'image/png'))));
        $this->assertSame([], $this->storedKeys());
    }

    public function test_it_rejects_a_tiff_renamed_png_and_stores_nothing(): void
    {
        $tiff = "II*\x00\x08\x00\x00\x00\x02\x00\x00\x01\x03\x00\x01\x00\x00\x00\x0a\x00\x00\x00\x01\x01\x03\x00\x01\x00\x00\x00\x0a\x00\x00\x00\x00\x00\x00\x00";
        $this->assertSame(RequestError::BAD_REQUEST, $this->code(fn () => ImageIntake::acceptImage($this->newUser(), 'layer', $this->upload($tiff, 'scan.png', 'image/png'))));
        $this->assertSame([], $this->storedKeys());
    }

    public function test_it_rejects_a_missing_file_a_wrong_declared_type_and_an_oversized_file(): void
    {
        $this->assertSame(RequestError::BAD_REQUEST, $this->code(fn () => ImageIntake::acceptImage($this->newUser(), 'content', null)));
        $this->assertSame(RequestError::BAD_REQUEST, $this->code(fn () => ImageIntake::acceptImage($this->newUser(), 'content', $this->upload('x', 'a.svg', 'image/svg+xml'))));
        $tooBig = $this->upload(str_repeat("\0", Storage::limit('image') + 1), 'big.png', 'image/png');
        $this->assertSame(RequestError::BAD_REQUEST, $this->code(fn () => ImageIntake::acceptImage($this->newUser(), 'content', $tooBig)));
        $this->assertSame([], $this->storedKeys());
    }

    public function test_it_refuses_the_upload_after_the_quota_of_60_and_stores_nothing_for_it(): void
    {
        $user = $this->newUser();
        $file = $this->upload($this->imageBytes(8, 8), 'tiny.png', 'image/png');
        for ($i = 0; $i < 60; $i++) {
            ImageIntake::acceptImage($user, 'content', $file);
        }
        $before = $this->storedKeys();
        $this->assertSame(RequestError::TOO_MANY_REQUESTS, $this->code(fn () => ImageIntake::acceptImage($user, 'content', $file)));
        $this->assertSame($before, $this->storedKeys());
        // Another user is unaffected.
        $this->assertArrayHasKey('key', ImageIntake::acceptImage($this->newUser(), 'content', $file));
    }

    public function test_it_accepts_content_and_layer_kinds_only(): void
    {
        $this->assertSame([true, true, false, false, false, false, false], array_map(
            fn ($value) => ImageIntake::isImageIntakeKind($value),
            ['content', 'layer', 'avatar', 'toString', '', null, 3],
        ));
    }
}
