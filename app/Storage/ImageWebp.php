<?php

namespace App\Storage;

use Illuminate\Http\UploadedFile;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Interfaces\ImageManagerInterface;
use RuntimeException;

/** Converts uploaded raster images to WebP. */
final class ImageWebp
{
    /** Decoding guard for every image read: refuses images with more pixels than a 64-megapixel photo (decompression bombs). */
    public const MAX_INPUT_PIXELS = 8000 * 8000;

    private const ALLOWED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF, IMAGETYPE_AVIF];

    /** Imagick when the extension is loaded (animated GIF/WebP stay animated), else GD. */
    public static function manager(): ImageManagerInterface
    {
        if (extension_loaded('imagick')) {
            // Defensive limits for the conversion: a hostile file must fail, not exhaust the server.
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_AREA, self::MAX_INPUT_PIXELS);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_TIME, 60);
        }

        return ImageManager::usingDriver(extension_loaded('imagick') ? ImagickDriver::class : GdDriver::class);
    }

    /** Number of frames of a GIF or WebP, counted from the bytes without decoding any pixel (1 for other formats). */
    public static function frameCount(string $bytes, int $type): int
    {
        $length = strlen($bytes);
        if ($type === IMAGETYPE_WEBP) {
            $frames = 0;
            for ($offset = 12; $offset + 8 <= $length;) {
                $frames += substr($bytes, $offset, 4) === 'ANMF' ? 1 : 0;
                $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
                $offset += 8 + $size + ($size & 1);
            }

            return max(1, $frames);
        }
        if ($type !== IMAGETYPE_GIF || $length < 13) {
            return 1;
        }
        $flags = ord($bytes[10]);
        $offset = 13 + ($flags & 0x80 ? 3 * (2 << ($flags & 7)) : 0);
        $frames = 0;
        $skipBlocks = function (int $at) use ($bytes, $length): int {
            while ($at < $length && ($size = ord($bytes[$at])) !== 0) {
                $at += $size + 1;
            }

            return $at + 1;
        };
        while ($offset < $length) {
            $marker = ord($bytes[$offset]);
            if ($marker === 0x21) {
                $offset = $skipBlocks($offset + 2);
            } elseif ($marker === 0x2C) {
                $frames++;
                $local = $offset + 9 < $length ? ord($bytes[$offset + 9]) : 0;
                $offset += 10 + ($local & 0x80 ? 3 * (2 << ($local & 7)) : 0);
                $offset = $skipBlocks($offset + 1);
            } else {
                break;
            }
        }

        return max(1, $frames);
    }

    /**
     * Width, height and type of the real format of `$bytes`, whatever the file name or declared type say.
     * Throws for anything that is not a JPEG, PNG, WebP, GIF or AVIF image (SVG, TIFF, HEIC… under a `.png` name)
     * or has more pixels than `MAX_INPUT_PIXELS` (width × height × frames, counted from the bytes before any decode).
     *
     * @return array{width: int, height: int, type: int}
     */
    public static function inspect(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], self::ALLOWED_TYPES, true)) {
            throw new UnsupportedImageException($info === false ? null : image_type_to_extension($info[2], false));
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] * self::frameCount($bytes, $info[2]) > self::MAX_INPUT_PIXELS) {
            throw new UnsupportedImageException('too many pixels');
        }

        return ['width' => $info[0], 'height' => $info[1], 'type' => $info[2]];
    }

    /** Drops EXIF, XMP, ICC and every other profile: the imagick driver keeps them unless stripped. */
    public static function stripMetadata(ImageInterface $image): void
    {
        $native = $image->core()->native();
        if ($native instanceof \Imagick) {
            $native->stripImage();
        }
    }

    /** True when a WebP carries an `EXIF` or `XMP ` chunk. */
    public static function webpHasMetadata(string $bytes): bool
    {
        $length = strlen($bytes);
        $offset = 12;
        while ($offset + 8 <= $length) {
            $fourcc = substr($bytes, $offset, 4);
            if ($fourcc === 'EXIF' || $fourcc === 'XMP ') {
                return true;
            }
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
            $offset += 8 + $size + ($size & 1);
        }

        return false;
    }

    /**
     * Stores every uploaded raster image as WebP inside the `$targetName` box: EXIF orientation applied, never
     * upscaled, animated GIF kept animated as WebP (imagick only), all metadata (EXIF, XMP, GPS) dropped. A WebP
     * that already fits the box and carries no EXIF/XMP (the browser compressed it) is kept byte-for-byte so it is
     * not lossy-encoded twice. Throws when the bytes are not a decodable JPEG, PNG, WebP, GIF or AVIF image
     * (`UnsupportedImageException` for other formats).
     */
    public static function toWebp(UploadedFile $file, string $targetName): UploadedFile
    {
        $target = ImageTargets::get($targetName);
        $bytes = file_get_contents($file->getPathname());
        if ($bytes === false) {
            throw new RuntimeException('Could not read the uploaded file.');
        }
        $info = self::inspect($bytes);
        $name = ImageTargets::webpName($file->getClientOriginalName());
        if ($info['type'] === IMAGETYPE_WEBP
            && $info['width'] <= $target['maxWidth'] && $info['height'] <= $target['maxHeight']
            && ! self::webpHasMetadata($bytes)) {
            return TempFile::fromBytes($bytes, $name, 'image/webp');
        }
        // The encoder writes no metadata unless asked to.
        $image = self::manager()->decodeBinary($bytes)
            ->orient()
            ->scaleDown(width: $target['maxWidth'], height: $target['maxHeight']);
        self::stripMetadata($image);
        $out = $image->encodeUsingFormat(Format::WEBP, quality: (int) round($target['quality'] * 100))->toString();

        return TempFile::fromBytes($out, $name, 'image/webp');
    }
}
