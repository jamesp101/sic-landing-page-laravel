<?php

namespace Tests\Unit\Carousel;

use App\Storage\Storage;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage as Disks;

/** Points App\Storage\Storage at a private temporary `uploads` root (local driver) and seeds objects on it. */
trait StoresFiles
{
    private int $objects = 0;

    protected function useLocalUploads(): void
    {
        // A root of its own: other areas' tests fake the shared `uploads` disk concurrently.
        $root = sys_get_temp_dir().'/sic-trees-'.bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        config(['sic.storage.driver' => 'local', 'sic.storage.public_url' => '', 'filesystems.disks.uploads.root' => $root]);
        Disks::purge('uploads');
        $this->beforeApplicationDestroyed(function () use ($root): void {
            (new Filesystem)->deleteDirectory($root);
        });
    }

    /** A stored object under `$key` (default a fresh `videos/<hex>.mp4`), returned as its bare key. */
    protected function stored(?string $key = null): string
    {
        $key ??= 'videos/'.str_pad(dechex(++$this->objects), 32, '0', STR_PAD_LEFT).'.mp4';
        Storage::putBytes($key, 'x', 'application/octet-stream');

        return $key;
    }

    protected function has(?string $key): bool
    {
        return $key !== null && Disks::disk('uploads')->exists($key);
    }

    /** Keys on the disk under `$prefix`, sorted. */
    protected function keysUnder(string $prefix): array
    {
        $keys = array_values(array_filter(Disks::disk('uploads')->allFiles(), fn (string $k): bool => str_starts_with($k, $prefix.'/')));
        sort($keys);

        return $keys;
    }

    protected function photo(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'photo');
        file_put_contents($path, "\x01\x02\x03");

        return new UploadedFile($path, 'photo.webp', 'image/webp', null, true);
    }
}
