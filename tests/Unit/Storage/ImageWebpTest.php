<?php

namespace Tests\Unit\Storage;

use App\Storage\ImageTargets;
use App\Storage\ImageWebp;
use App\Storage\UnsupportedImageException;
use Tests\TestCase;

class ImageWebpTest extends TestCase
{
    use UsesUploadsDisk;

    public function test_it_re_encodes_jpeg_png_as_webp_inside_the_target_box_without_upscaling(): void
    {
        $big = ImageWebp::toWebp($this->upload($this->imageBytes(4000, 3000, 'jpeg'), 'Photo.JPG', 'image/jpeg'), 'highlight');
        $this->assertSame('image/webp', $big->getClientMimeType());
        $this->assertSame('Photo.webp', $big->getClientOriginalName());
        $this->assertSame([ImageTargets::TARGETS['highlight']['maxWidth'], 1200, IMAGETYPE_WEBP], $this->sizeOf(file_get_contents($big->getPathname())));

        $small = ImageWebp::toWebp($this->upload($this->imageBytes(120, 80, 'png'), 'logo.png', 'image/png'), 'logo');
        $this->assertSame([120, 80, IMAGETYPE_WEBP], $this->sizeOf(file_get_contents($small->getPathname())));
    }

    public function test_it_applies_exif_orientation_before_resizing(): void
    {
        // 600x300 stored, orientation 6 = displayed rotated 90°, i.e. 300x600.
        $out = ImageWebp::toWebp($this->upload($this->jpegWithOrientation(600, 300, 6), 'phone.jpg', 'image/jpeg'), 'portrait');
        $this->assertSame([240, 480, IMAGETYPE_WEBP], $this->sizeOf(file_get_contents($out->getPathname())));
    }

    public function test_it_keeps_a_webp_that_already_fits_byte_for_byte(): void
    {
        $webp = $this->imageBytes(300, 200, 'webp');
        $out = ImageWebp::toWebp($this->upload($webp, 'already.webp', 'image/webp'), 'content');
        $this->assertSame($webp, file_get_contents($out->getPathname()));
        $this->assertSame('already.webp', $out->getClientOriginalName());
    }

    public function test_it_re_encodes_a_fitting_webp_that_carries_exif_and_drops_the_metadata(): void
    {
        $tagged = $this->webpWithExif(300, 200);
        $this->assertTrue(ImageWebp::webpHasMetadata($tagged));
        $this->assertFalse(ImageWebp::webpHasMetadata($this->imageBytes(300, 200, 'webp')));

        $converted = ImageWebp::toWebp($this->upload($tagged, 'tagged.webp', 'image/webp'), 'content');
        $out = file_get_contents($converted->getPathname());
        $this->assertSame([300, 200, IMAGETYPE_WEBP], $this->sizeOf($out));
        $this->assertFalse(ImageWebp::webpHasMetadata($out));
        $this->assertNotSame($tagged, $out);
    }

    public function test_it_scales_a_webp_that_exceeds_the_box_down(): void
    {
        $out = ImageWebp::toWebp($this->upload($this->imageBytes(1000, 400, 'webp'), 'wide.webp', 'image/webp'), 'logo');
        $this->assertSame([512, 205, IMAGETYPE_WEBP], $this->sizeOf(file_get_contents($out->getPathname())));
    }

    public function test_it_accepts_every_allowed_real_format_whatever_the_file_says(): void
    {
        foreach (['jpeg', 'png', 'gif', 'avif', 'webp'] as $format) {
            $out = ImageWebp::toWebp($this->upload($this->imageBytes(64, 48, $format), 'photo.png', 'image/png'), 'content');
            $this->assertSame([64, 48, IMAGETYPE_WEBP], $this->sizeOf(file_get_contents($out->getPathname())), $format);
        }
    }

    public function test_it_refuses_formats_that_are_not_allowlisted_whatever_the_file_says(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40"/></svg>';
        foreach ([$svg, "<html>\n", 'II*'."\0\x08\0\0\0", imagecreatetruecolor(2, 2) ? $this->bmp() : ''] as $bytes) {
            try {
                ImageWebp::toWebp($this->upload($bytes, 'x.png', 'image/png'), 'content');
                $this->fail('must be refused: '.substr($bytes, 0, 8));
            } catch (UnsupportedImageException $error) {
                $this->assertStringStartsWith('Unsupported image format', $error->getMessage());
            }
        }
    }

    public function test_it_refuses_images_with_more_pixels_than_the_decoding_guard(): void
    {
        // A PNG header declaring 9000 x 9000 pixels.
        $header = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('N', 9000).pack('N', 9000)."\x08\x02\x00\x00\x00".pack('N', 0);
        $this->expectException(UnsupportedImageException::class);
        ImageWebp::toWebp($this->upload($header, 'bomb.png', 'image/png'), 'content');
    }

    public function test_it_refuses_an_animated_image_whose_frames_together_exceed_the_pixel_guard(): void
    {
        // A 1x1 GIF whose canvas is patched to 4000x4000 with its frame repeated 5 times: 80 megapixels over all frames.
        $one = $this->imageBytes(1, 1, 'gif');
        $flags = ord($one[10]);
        $start = 13 + ($flags & 0x80 ? 3 * (2 << ($flags & 7)) : 0);
        $frame = substr($one, $start, -1);
        $many = fn (int $n) => substr($one, 0, 6).pack('v', 4000).pack('v', 4000).substr($one, 10, $start - 10).str_repeat($frame, $n).';';

        $this->assertSame(5, ImageWebp::frameCount($many(5), IMAGETYPE_GIF));
        $this->assertSame(1, ImageWebp::frameCount($one, IMAGETYPE_GIF));
        $this->expectException(UnsupportedImageException::class);
        ImageWebp::inspect($many(5));
    }

    public function test_a_few_frames_within_the_guard_are_accepted(): void
    {
        $one = $this->imageBytes(1, 1, 'gif');
        $flags = ord($one[10]);
        $start = 13 + ($flags & 0x80 ? 3 * (2 << ($flags & 7)) : 0);
        $gif = substr($one, 0, 6).pack('v', 4000).pack('v', 4000).substr($one, 10, $start - 10).str_repeat(substr($one, $start, -1), 3).';';
        $this->assertSame(4000, ImageWebp::inspect($gif)['width']);
    }

    public function test_animated_gifs_stay_animated_when_imagick_is_available(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Animated GIF → animated WebP needs the imagick extension.');
        }
        $frames = new \Imagick;
        foreach (['red', 'lime', 'blue'] as $colour) {
            $frame = new \Imagick;
            $frame->newImage(800, 800, new \ImagickPixel($colour));
            $frame->setImageFormat('gif');
            $frame->setImageDelay(10);
            $frames->addImage($frame);
        }
        $frames->setImageFormat('gif');
        $gif = $frames->getImagesBlob();
        $out = ImageWebp::toWebp($this->upload($gif, 'spin.gif', 'image/gif'), 'portrait');
        $image = ImageWebp::manager()->decodeBinary(file_get_contents($out->getPathname()));
        $this->assertTrue($image->isAnimated());
        $this->assertSame([480, 480], [$image->width(), $image->height()]);
    }

    private function bmp(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagebmp($image);

        return (string) ob_get_clean();
    }
}
