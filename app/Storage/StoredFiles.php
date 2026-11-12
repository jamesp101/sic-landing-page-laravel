<?php

namespace App\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stored-file lifecycle: the one place that decides when uploaded files are written and removed around a database
 * write.
 *
 * Rules: new files are stored first; if the commit fails the new files are removed and the error is rethrown; if
 * it succeeds the stale files are removed. Cleanup failures are logged and never undo a new file or fail the request.
 */
final class StoredFiles
{
    /**
     * Stores the new files, runs the commit, then removes stale keys (see the class rules).
     *
     * `$commit` keys:
     * - `files`: name => `['prefix' => string, 'file' => UploadedFile|ImageUpload, 'variant' => bool]` or null/absent
     *   (skipped). `variant` also stores the image's variants in the same commit (a failure is logged; the page falls
     *   back to the original).
     * - `adopted`: keys already stored (e.g. a direct upload) that belong to the commit and are removed if it fails.
     * - `run`: `callable(array $keys)` writing the row(s); `$keys[name]` is the stored key of each new file, null for
     *   an empty one.
     * - `stale`: keys that became unused because the commit succeeded: a list, or a callable returning one (run after
     *   the commit, e.g. to find files no row references any more).
     *
     * @param  array{files?: array<string, array{prefix: string, file: UploadedFile, variant?: bool}|null>, adopted?: iterable<?string>, run: callable, stale?: iterable<?string>|callable|null}  $commit
     */
    public static function commitWithFiles(array $commit): mixed
    {
        $keys = [];
        $stored = [];
        foreach ($commit['adopted'] ?? [] as $key) {
            if ($key !== null && $key !== '') {
                $stored[] = $key;
            }
        }
        try {
            foreach ($commit['files'] ?? [] as $name => $upload) {
                $keys[$name] = null;
                if (! $upload) {
                    continue;
                }
                // Image conversion happens here, after the caller's sign-in and ownership checks.
                $file = ImageFile::storedImage($upload['file']);
                $key = Storage::put($upload['prefix'], $file);
                $stored[] = $key;
                $keys[$name] = $key;
                if (! empty($upload['variant'])) {
                    self::storeVariant($key, $file);
                }
            }
            $result = ($commit['run'])($keys);
            self::cleanup($commit['stale'] ?? null, 'replaced');

            return $result;
        } catch (Throwable $error) {
            // `cleanup` never throws, so this only sees conversion / put / commit failures.
            self::removeAll($stored, 'rolled back');
            throw $error;
        }
    }

    /**
     * Runs the row delete, then removes the files the row owned.
     *
     * @param  iterable<?string>|callable  $owned
     */
    public static function deleteWithFiles(callable $run, iterable|callable $owned): mixed
    {
        $result = $run();
        self::cleanup($owned, 'deleted');

        return $result;
    }

    /**
     * Removes stored files that no row owns (e.g. a rejected direct upload); failures are logged, never thrown.
     *
     * @param  iterable<?string>  $keys
     */
    public static function discardFiles(iterable $keys, string $context): void
    {
        self::removeAll($keys, $context);
    }

    /** @param  iterable<?string>|callable|null  $stale */
    private static function cleanup(iterable|callable|null $stale, string $context): void
    {
        if ($stale === null) {
            return;
        }
        try {
            self::removeAll((! is_array($stale) && is_callable($stale)) ? $stale() : $stale, $context);
        } catch (Throwable $error) {
            Log::error("Stored file cleanup failed ({$context})", ['exception' => $error]);
        }
    }

    /** @param  iterable<?string>  $keys */
    private static function removeAll(iterable $keys, string $context): void
    {
        $all = [];
        foreach ($keys as $key) {
            if ($key !== null && $key !== '') {
                array_push($all, $key, ...Variants::variantKeysOf($key));
            }
        }
        foreach ($all as $key) {
            try {
                Storage::remove($key);
            } catch (Throwable $error) {
                Log::error("Stored file cleanup failed ({$context}): {$key}", ['exception' => $error]);
            }
        }
    }

    private static function storeVariant(string $key, UploadedFile $file): void
    {
        try {
            $bytes = file_get_contents($file->getPathname());
            if ($bytes === false) {
                throw new \RuntimeException('Could not read the stored image.');
            }
            ImageVariant::putVariants($key, $bytes);
        } catch (Throwable $error) {
            Log::error("Image variant failed: {$key}", ['exception' => $error]);
        }
    }
}
