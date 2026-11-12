<?php

namespace App\Storage;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Aws\S3\StreamWrapper;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage as Disks;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToRetrieveMetadata;
use RuntimeException;
use Throwable;

/**
 * Uploaded-file storage. `sic.storage.driver` (STORAGE_DRIVER) picks the Laravel disk:
 * - `local` (default): disk `uploads`, files under UPLOAD_DIR on the server's disk.
 * - `s3`: disk `uploads-s3`, an S3-compatible bucket (S3_BUCKET, S3_REGION, S3_ENDPOINT, S3_ACCESS_KEY_ID,
 *   S3_SECRET_ACCESS_KEY, S3_FORCE_PATH_STYLE, S3_PREFIX).
 *
 * The database stores bare keys (`posts/abc.jpg`). URLs default to `/storage/<key>`, served by
 * `StorageController` for both drivers, which keeps legacy `/storage/...` links working. With S3_PUBLIC_URL set
 * (bucket website, CDN), `publicUrl` points straight at the bucket and the route redirects there instead.
 */
final class Storage
{
    public const MB = 1024 * 1024;

    public const PREFIXES = ['posts', 'posts/inline', 'downloads', 'carousels', 'departments', 'members', 'videos'];

    /** Multipart bodies must stay under Vercel's ~4.5 MB request limit on `s3`; larger downloads use direct uploads. */
    private const MULTIPART_BUDGET = 4 * self::MB;

    /** Lifetime of a presigned upload URL. */
    private const PRESIGN_MINUTES = 10;

    // ---- driver ---------------------------------------------------------------------------------------------

    public static function driverName(): string
    {
        $name = strtolower(trim((string) config('sic.storage.driver', 'local')));
        if ($name !== 'local' && $name !== 's3') {
            throw new InvalidArgumentException('Unsupported STORAGE_DRIVER "'.$name.'" (expected "local" or "s3")');
        }

        return $name;
    }

    /**
     * True when the browser uploads downloads straight to the bucket through a presigned URL (Vercel functions
     * reject request bodies over ~4.5 MB), so the multipart upload path is only for small files.
     */
    public static function directUploads(): bool
    {
        return self::driverName() === 's3';
    }

    /** Largest accepted upload in bytes: `image`, `memberImage`, `download`, `video`. */
    public static function limit(string $name): int
    {
        return self::limits()[$name] ?? throw new InvalidArgumentException("Unknown upload limit {$name}");
    }

    /**
     * Videos never travel through actions or multipart: `s3` uploads straight to the bucket, `local` streams the
     * request body to disk (`UploadController::videoBytes`).
     *
     * @return array{image: int, memberImage: int, download: int, video: int}
     */
    public static function limits(): array
    {
        return [
            'image' => self::directUploads() ? self::MULTIPART_BUDGET : 5 * self::MB,
            'memberImage' => 2 * self::MB,
            'download' => 20 * self::MB,
            'video' => VideoTypes::MAX_BYTES,
        ];
    }

    /** Absolute base for direct object URLs (no trailing slash), or null to serve through /storage. */
    public static function directBaseUrl(): ?string
    {
        $base = rtrim(trim((string) config('sic.storage.public_url', '')), '/');

        return self::directUploads() && $base !== '' ? $base : null;
    }

    public static function disk(): FilesystemAdapter
    {
        if (self::directUploads() && ! config('filesystems.disks.uploads-s3.bucket')) {
            throw new RuntimeException('S3_BUCKET is required when STORAGE_DRIVER=s3');
        }

        return Disks::disk(self::directUploads() ? 'uploads-s3' : 'uploads');
    }

    // ---- keys and URLs --------------------------------------------------------------------------------------

    /** Accepts a bare key, `/storage/<key>` or `storage/<key>` and returns a validated bare key. */
    public static function toKey(string $value): string
    {
        $key = preg_replace('#^/+#', '', preg_replace('#^/?storage/#', '', $value, 1));
        $normalized = self::normalize($key);
        if ($key === '' || $normalized !== $key || str_starts_with($normalized, '..') || str_contains($key, '\\') || str_contains($key, "\0")) {
            throw new InvalidArgumentException('Invalid storage key');
        }

        return $key;
    }

    /** POSIX path normalisation (collapses `//`, `.` and `..`), as the key check needs. */
    private static function normalize(string $key): string
    {
        $parts = [];
        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts && end($parts) !== '..') {
                    array_pop($parts);
                } else {
                    $parts[] = '..';
                }

                continue;
            }
            $parts[] = $segment;
        }
        $normalized = implode('/', $parts);

        return str_ends_with($key, '/') && $normalized !== '' ? $normalized.'/' : $normalized;
    }

    private static function isExternal(string $value): bool
    {
        return preg_match('#^https?://#', $value) === 1;
    }

    /** Public URL for a stored key. Absolute http(s) URLs and non-storage site paths pass through. */
    public static function publicUrl(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (self::isExternal($value)) {
            return $value;
        }
        if (str_starts_with($value, '/') && ! str_starts_with($value, '/storage/')) {
            return $value;
        }
        $key = self::toKey($value);
        $base = self::directBaseUrl();

        return $base !== null ? "{$base}/{$key}" : "/storage/{$key}";
    }

    /** The public URL of a non-empty stored key (`publicUrl` for a column that always holds one); throws on an empty key. */
    public static function storedUrl(string $key): string
    {
        return self::publicUrl($key) ?? throw new InvalidArgumentException('storedUrl needs a stored key');
    }

    /**
     * URL of the `$width` px variant of a stored image whose kind has one (`Variants`), else null. Always the app
     * route: it serves the original when the variant is missing (not backfilled yet, or never made), which a direct
     * bucket URL could not.
     */
    public static function variantUrl(?string $value, int $width): ?string
    {
        if ($value === null || $value === '' || self::isExternal($value) || (str_starts_with($value, '/') && ! str_starts_with($value, '/storage/'))) {
            return null;
        }
        $variantKey = Variants::variantKeyOf(self::toKey($value), $width);

        return $variantKey !== null ? "/storage/{$variantKey}" : null;
    }

    /**
     * The app route URL of a stored key (`/storage/<key>`) even when `S3_PUBLIC_URL` is set. Use it for URLs kept
     * in content that must not depend on where files are hosted; the route redirects inline requests to the
     * bucket base, which answers `Range` itself.
     */
    public static function storageRouteUrl(string $value): string
    {
        return '/storage/'.self::toKey($value);
    }

    /** Inverse of `publicUrl`: the bare key of one of our own file URLs (`/storage/<key>` or the direct base), else null. */
    public static function keyFromPublicUrl(string $url): ?string
    {
        $base = self::directBaseUrl();
        $prefix = null;
        foreach ($base !== null ? ["{$base}/", '/storage/'] : ['/storage/'] as $candidate) {
            if (str_starts_with($url, $candidate)) {
                $prefix = $candidate;
                break;
            }
        }
        if ($prefix === null) {
            return null;
        }
        $rest = substr($url, strlen($prefix));
        try {
            return self::toKey($rest) === $rest ? $rest : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Inverse of `publicUrl` for uploaded videos: the bare key when `$url` is one of our file URLs directly under
     * `videos/` with a video extension, else null.
     */
    public static function videoKeyFromUrl(string $url): ?string
    {
        $key = self::keyFromPublicUrl($url);
        if ($key === null) {
            return null;
        }
        $parts = explode('/', $key);
        if (count($parts) !== 2 || $parts[0] !== 'videos' || $parts[1] === '') {
            return null;
        }

        return isset(VideoTypes::TYPES[FileTypes::extensionOf($parts[1])]) ? $key : null;
    }

    /** URL that downloads the object as an attachment named `$fileName` (always via the app route). */
    public static function downloadUrl(string $value, string $fileName): string
    {
        if (self::isExternal($value)) {
            return $value;
        }

        return '/storage/'.self::toKey($value).'?download='.strtr(rawurlencode($fileName), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']); // encodeURIComponent
    }

    // ---- writing --------------------------------------------------------------------------------------------

    /** Stores a file under `$prefix/` with a random name and returns its key; type and extension come from `FileTypes::storedType`. */
    public static function put(string $prefix, UploadedFile $file): string
    {
        ['ext' => $ext, 'contentType' => $contentType] = FileTypes::storedType($file->getClientOriginalName(), (string) $file->getClientMimeType());
        $key = "{$prefix}/".bin2hex(random_bytes(16)).$ext;
        $source = fopen($file->getPathname(), 'rb');
        if ($source === false) {
            throw new RuntimeException('Could not read the uploaded file.');
        }
        try {
            self::putResource($key, $source, $contentType);
        } finally {
            fclose($source);
        }

        return $key;
    }

    /** Stores bytes under an exact key (variants, tests). */
    public static function putBytes(string $key, string $bytes, string $contentType): void
    {
        $source = fopen('php://temp', 'w+b');
        fwrite($source, $bytes);
        rewind($source);
        try {
            self::putResource(self::toKey($key), $source, $contentType);
        } finally {
            fclose($source);
        }
    }

    /** @param resource $source */
    private static function putResource(string $key, $source, string $contentType): void
    {
        if (self::driverName() === 's3') {
            self::disk()->put($key, $source, ['mimetype' => $contentType]);

            return;
        }
        self::writePartial($key, function ($sink) use ($source): void {
            if (stream_copy_to_stream($source, $sink) === false) {
                throw new RuntimeException('Could not write the file.');
            }
        });
    }

    /** Absolute path of `$key` on the `local` driver. */
    public static function resolve(string $key): string
    {
        $key = self::toKey($key);

        return rtrim(self::disk()->path(''), '/\\').'/'.$key;
    }

    /**
     * The one partial-file write of the `local` driver: bytes go to a temporary sibling that is renamed into place
     * on success, so a failed upload never leaves a partial file under its key. On failure the sink is closed
     * before the partial file is removed. Keep any new local write on this path.
     *
     * @template T
     *
     * @param  callable(resource): T  $fill
     * @return T
     */
    private static function writePartial(string $key, callable $fill): mixed
    {
        $full = self::resolve($key);
        $dir = dirname($full);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create the upload directory.');
        }
        $partial = $full.'.'.bin2hex(random_bytes(6)).'.part';
        $sink = fopen($partial, 'wb');
        if ($sink === false) {
            throw new RuntimeException('Could not open the file for writing.');
        }
        try {
            $result = $fill($sink);
            if (! fflush($sink) || ! fclose($sink)) {
                throw new RuntimeException('Could not write the file.');
            }
            if (! rename($partial, $full)) {
                throw new RuntimeException('Could not store the file.');
            }

            return $result;
        } catch (Throwable $error) {
            if (is_resource($sink)) {
                fclose($sink);
            }
            @unlink($partial);
            throw $error;
        }
    }

    /**
     * Streams `$body` to `$key` on disk, never holding it in memory, and returns the bytes written. Throws
     * `UploadTooLargeException` (nothing is left behind) as soon as more than `$maxBytes` arrive. Only valid for
     * `local`; `s3` uploads go straight to the bucket (`presignUpload`).
     *
     * @param  resource  $body
     */
    public static function receiveUpload(string $key, $body, int $maxBytes): int
    {
        if (self::driverName() !== 'local') {
            throw new RuntimeException('Streamed uploads require STORAGE_DRIVER=local');
        }

        return self::writePartial(self::toKey($key), function ($sink) use ($body, $maxBytes): int {
            $written = 0;
            while (! feof($body)) {
                $chunk = fread($body, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('The upload stream failed.');
                }
                if ($chunk === '') {
                    continue;
                }
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    throw new UploadTooLargeException($maxBytes);
                }
                if (fwrite($sink, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Could not write the file.');
                }
            }

            return $written;
        });
    }

    /** Presigned PUT URL (10 minutes) for a browser upload of exactly `$size` bytes of `$contentType`. Only valid for `s3`. */
    public static function presignUpload(string $key, string $contentType, int $size): string
    {
        if (! self::directUploads()) {
            throw new RuntimeException('Direct uploads require STORAGE_DRIVER=s3');
        }
        $disk = self::disk();
        $prefix = trim((string) ($disk->getConfig()['root'] ?? ''), '/');
        $key = self::toKey($key);
        if (! $disk instanceof AwsS3V3Adapter) {
            throw new RuntimeException('Direct uploads need an S3 disk.');
        }

        // Content type and length are signed: the browser cannot store a different type or size than the server approved.
        return S3Presigner::putUrl($disk->getClient(), (string) $disk->getConfig()['bucket'], $prefix !== '' ? "{$prefix}/{$key}" : $key, $contentType, $size, self::PRESIGN_MINUTES * 60);
    }

    // ---- removing -------------------------------------------------------------------------------------------

    /** Deletes a stored object; missing objects and external URLs are ignored. */
    public static function remove(?string $value): void
    {
        if ($value === null || $value === '' || self::isExternal($value)) {
            return;
        }
        $key = self::toKey($value);
        if (self::driverName() === 'local') {
            $path = self::resolve($key);
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException("Could not remove {$key}.");
            }

            return;
        }
        self::disk()->delete($key);
    }

    /**
     * Deletes the given objects and the variants of each (`Variants`).
     *
     * @param  iterable<?string>  $keys
     */
    public static function removeAll(iterable $keys): void
    {
        foreach ($keys as $key) {
            if ($key === null || $key === '') {
                continue;
            }
            self::remove($key);
            foreach (Variants::variantKeysOf($key) as $variant) {
                self::remove($variant);
            }
        }
    }

    // ---- reading --------------------------------------------------------------------------------------------

    /** Size of a stored object (e.g. one the browser uploaded straight to the bucket), or null when missing. Never opens the body. */
    public static function head(string $key): ?int
    {
        $key = self::toKey($key);
        if (self::driverName() === 'local') {
            $path = self::resolve($key);

            return is_file($path) ? (int) filesize($path) : null;
        }
        try {
            return self::disk()->getDriver()->fileSize($key);
        } catch (UnableToRetrieveMetadata $error) {
            $previous = $error->getPrevious();
            if ($previous === null || ($previous instanceof AwsException && $previous->getStatusCode() === 404)) {
                return null;
            }
            throw $error;
        }
    }

    /** Alias of `head` named as in the TS module. */
    public static function headUpload(string $value): ?int
    {
        return self::head($value);
    }

    /**
     * The key to serve for a request: `$key` itself, except for a variant of an image kind that has variants
     * (`Variants`) that is missing, which is served by its original. Any other key, and a variant whose original is
     * gone too (or invalid), returns `$key` untouched, so the route answers 404 after at most one HEAD per
     * candidate original.
     */
    public static function servedKey(string $key): string
    {
        $candidates = Variants::originalCandidates($key);
        if ($candidates === []) {
            return $key;
        }
        try {
            if (self::head($key) !== null) {
                return $key;
            }
            foreach ($candidates as $candidate) {
                if (self::head($candidate) !== null) {
                    return $candidate;
                }
            }
        } catch (InvalidArgumentException) {
            // invalid key: let the route answer 404
        }

        return $key;
    }

    /**
     * Opens a stored object (or only `$range` of it), or null when missing or the key is invalid. Throws
     * `RangeNotSatisfiableException` for a range that selects no byte.
     *
     * @param  array{start?: int, end?: int|null, suffix?: int}|null  $range
     */
    public static function read(string $value, ?array $range = null): ?StoredObject
    {
        try {
            $key = self::toKey($value);
        } catch (InvalidArgumentException) {
            return null;
        }

        return self::get($key, $range);
    }

    /** Alias of `read` named as in the driver interface (`get(key, range)`). */
    public static function get(string $key, ?array $range = null): ?StoredObject
    {
        $key = self::toKey($key);
        $disk = self::disk();
        if (self::driverName() === 'local') {
            return self::getLocal(self::resolve($key), $range);
        }
        if ($disk instanceof AwsS3V3Adapter) {
            return self::getS3($disk, $key, $range);
        }

        // Any other Laravel disk standing in for the bucket (tests).
        $size = self::head($key);
        if ($size === null) {
            return null;
        }
        $slice = $range ? Range::resolveRange($range, $size) : null;
        if ($range && ! $slice) {
            throw new RangeNotSatisfiableException($size);
        }
        $body = $disk->readStream($key);
        if ($slice) {
            fseek($body, $slice['start']);
        }

        return new StoredObject($body, $size, null, null, $slice);
    }

    /** @param  array{start?: int, end?: int|null, suffix?: int}|null  $range */
    private static function getLocal(string $path, ?array $range): ?StoredObject
    {
        if (! is_file($path)) {
            return null;
        }
        $size = (int) filesize($path);
        $slice = $range ? Range::resolveRange($range, $size) : null;
        if ($range && ! $slice) {
            throw new RangeNotSatisfiableException($size);
        }
        $body = @fopen($path, 'rb');
        if ($body === false) {
            return null;
        }
        if ($slice && $slice['start'] > 0) {
            fseek($body, $slice['start']);
        }

        return new StoredObject($body, $size, (int) filemtime($path), null, $slice);
    }

    /** @param  array{start?: int, end?: int|null, suffix?: int}|null  $range */
    private static function getS3(AwsS3V3Adapter $disk, string $key, ?array $range): ?StoredObject
    {
        /** @var S3Client $client */
        $client = $disk->getClient();
        $config = $disk->getConfig();
        $prefix = trim((string) ($config['root'] ?? ''), '/');
        $args = ['Bucket' => $config['bucket'], 'Key' => $prefix !== '' ? "{$prefix}/{$key}" : $key];
        try {
            $result = $client->getObject($args + ($range ? ['Range' => Range::rangeHeaderValue($range)] : []));
        } catch (AwsException $error) {
            $status = $error->getStatusCode();
            if ($status === 404 || $error->getAwsErrorCode() === 'NoSuchKey') {
                return null;
            }
            if ($status === 416) {
                throw new RangeNotSatisfiableException((int) ($client->headObject($args)['ContentLength'] ?? 0));
            }
            throw $error;
        }
        // `ContentRange` is `bytes <start>-<end>/<total>`; a store that ignores Range sends the whole object.
        $served = preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', (string) ($result['ContentRange'] ?? ''), $match) === 1;
        $modified = $result['LastModified'] ?? null;

        return new StoredObject(
            StreamWrapper::getResource($result['Body']),
            $served ? (int) $match[3] : (int) ($result['ContentLength'] ?? 0),
            $modified instanceof \DateTimeInterface ? $modified->getTimestamp() : null,
            $result['ContentType'] ?? null,
            $served ? ['start' => (int) $match[1], 'end' => (int) $match[2]] : null,
        );
    }

    /**
     * Every stored object whose key starts with `$prefix` (all when omitted), as bare keys. Includes unfinished
     * temporary files.
     *
     * @return \Generator<int, array{key: string, size: int, mtime: int}>
     */
    public static function list(string $prefix = ''): \Generator
    {
        try {
            foreach (self::disk()->getDriver()->listContents('', true) as $item) {
                if (! $item->isFile() || ! str_starts_with($item->path(), $prefix)) {
                    continue;
                }
                yield ['key' => $item->path(), 'size' => (int) $item->fileSize(), 'mtime' => (int) ($item->lastModified() ?? time())];
            }
        } catch (FilesystemException $error) {
            throw new RuntimeException('Could not list the stored files: '.$error->getMessage(), 0, $error);
        }
    }
}
