<?php

namespace Tests\Unit\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage as Disks;

/** Points App\Storage\Storage at a temporary `uploads` disk (local driver, no S3). */
trait UsesUploadsDisk
{
    protected function useLocalUploads(): void
    {
        config([
            'sic.storage.driver' => 'local',
            'sic.storage.public_url' => '',
        ]);
        Disks::fake('uploads');
    }

    /** Uses the `uploads-s3` disk name with a local temporary root standing in for the bucket. */
    protected function useFakeBucket(string $publicUrl = ''): void
    {
        config([
            'sic.storage.driver' => 's3',
            'sic.storage.public_url' => $publicUrl,
            'filesystems.disks.uploads-s3.bucket' => 'sic',
        ]);
        Disks::fake('uploads-s3');
    }

    /** Uses the real S3 disk configuration with dummy credentials: only offline operations (presigning) work. */
    protected function useS3Config(string $publicUrl = ''): void
    {
        config([
            'sic.storage.driver' => 's3',
            'sic.storage.public_url' => $publicUrl,
            'filesystems.disks.uploads-s3' => [
                'driver' => 's3',
                'key' => 'AKIATEST',
                'secret' => 'secret',
                'region' => 'ap-southeast-1',
                'bucket' => 'sic',
                'root' => 'site',
                'throw' => true,
                'report' => false,
            ],
        ]);
        Disks::purge('uploads-s3');
    }

    /** A file the way a browser upload arrives. */
    protected function upload(string $bytes, string $name, string $type = 'application/octet-stream'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sic-test-');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $type, null, true);
    }

    /** A readable stream over `$bytes`. */
    protected function stream(string $bytes)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    /** Bytes of a real image (`jpeg`, `png`, `webp`, `gif`, `avif`) of the given size. */
    protected function imageBytes(int $width = 40, int $height = 20, string $format = 'png'): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, 0x2266CC);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), intdiv($height, 2), 0xFFCC00);
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($image, null, 90),
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 80),
            'gif' => imagegif($image),
            'avif' => imageavif($image),
        };

        return (string) ob_get_clean();
    }

    /** Keys stored on the `uploads` disk, sorted. */
    protected function storedKeys(): array
    {
        $keys = Disks::disk('uploads')->allFiles();
        sort($keys);

        return $keys;
    }

    /** JPEG bytes with an EXIF orientation tag (6 = displayed rotated 90°). */
    protected function jpegWithOrientation(int $width, int $height, int $orientation): string
    {
        $jpeg = $this->imageBytes($width, $height, 'jpeg');
        $tiff = "MM\x00\x2a\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    /** A WebP of the given size carrying an EXIF chunk (VP8X wrapper around the encoded image). */
    protected function webpWithExif(int $width, int $height): string
    {
        $plain = $this->imageBytes($width, $height, 'webp');
        $chunks = '';
        $offset = 12;
        while ($offset + 8 <= strlen($plain)) {
            $size = unpack('V', substr($plain, $offset + 4, 4))[1];
            $chunk = substr($plain, $offset, 8 + $size + ($size & 1));
            $chunks .= $chunk;
            $offset += strlen($chunk);
        }
        $vp8x = 'VP8X'.pack('V', 10)."\x08\x00\x00\x00".substr(pack('V', $width - 1), 0, 3).substr(pack('V', $height - 1), 0, 3);
        $exif = 'EXIF'.pack('V', 4).'ABCD';
        $body = 'WEBP'.$vp8x.$chunks.$exif;

        return 'RIFF'.pack('V', strlen($body)).$body;
    }

    /** [width, height, IMAGETYPE_*] of stored/encoded bytes. */
    protected function sizeOf(string $bytes): array
    {
        $info = getimagesizefromstring($bytes);

        return [$info[0], $info[1], $info[2]];
    }
}
