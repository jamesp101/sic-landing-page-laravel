<?php

namespace App\Storage;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Stored-file lifecycle: the one place that decides when uploaded files are written and removed around a database
 * write. Which files a row owns is not told per call: each model declares it ({@see OwnsFiles},
 * {@see FileOwnership}) and the keys to keep, replace or remove are read off the row.
 *
 * Rules: new files are stored first; if the commit fails the new files are removed and the error is rethrown; if
 * it succeeds the stale files are removed. Cleanup failures are logged and never undo a new file or fail the request.
 */
final class StoredFiles
{
    /**
     * Stores the new files, writes the row, then removes the files the write made stale (see the class rules).
     *
     * - `$row`: the model to write; a new instance creates it. Returned after the write.
     * - `$attributes`: the columns to set, or `fn(array $keys): array` when they need the stored keys.
     * - `$uploads`: declared file column => upload (`UploadedFile`/`ImageUpload`) or null to keep the column. The new
     *   key is written to that column; the declaration gives the directory and whether Image variants are stored in
     *   the same commit (a variant failure is logged; the page falls back to the original).
     * - `$extras`: name => `['prefix' => string, 'file' => upload, 'variants'?: bool]` or null: files stored in the
     *   same commit that no column holds; the caller places `$keys[name]` inside `$attributes` (Layer images).
     * - `$write`: `fn(Model $row, array $attributes)` replacing the default `fill()->save()` (e.g. inside a transaction).
     *
     * A declared file column set to a key already stored (a direct upload) is adopted: removed if the write fails.
     * Stale: the previous key of every declared column the write changed, plus embedded keys the row no longer
     * references and nothing else does.
     *
     * @param  array<string, mixed>|Closure  $attributes
     * @param  array<string, mixed>  $uploads
     * @param  array<string, array{prefix: string, file: mixed, variants?: bool}|null>  $extras
     */
    public static function save(Model $row, array|Closure $attributes, array $uploads = [], array $extras = [], ?Closure $write = null): Model
    {
        $ownership = self::ownershipOf($row);
        $before = $row->exists ? $row->only($ownership->readColumns()) : [];
        $stored = [];
        try {
            $keys = [];
            foreach ($uploads as $column => $upload) {
                $declared = $ownership->columns[$column] ?? throw new LogicException($row::class." declares no file column {$column}.");
                $keys[$column] = $upload ? self::store($declared['prefix'], $upload, $declared['variants'], $stored) : null;
            }
            foreach ($extras as $name => $extra) {
                $keys[$name] = $extra ? self::store($extra['prefix'], $extra['file'], $extra['variants'] ?? false, $stored) : null;
            }
            $values = $attributes instanceof Closure ? $attributes($keys) : $attributes;
            foreach (array_keys($uploads) as $column) {
                if ($keys[$column] !== null) {
                    $values[$column] = $keys[$column];
                }
            }
            foreach (array_keys($ownership->columns) as $column) {
                $key = $values[$column] ?? null;
                if ($key !== null && $key !== '' && $key !== ($before[$column] ?? null) && ! in_array($key, $stored, true)) {
                    $stored[] = $key;
                }
            }
            $write === null ? $row->fill($values)->save() : $write($row, $values);
            self::cleanup(fn (): array => self::staleKeys($row, $ownership, $before), 'replaced');

            return $row;
        } catch (Throwable $error) {
            // `cleanup` never throws, so this only sees conversion / put / write failures.
            self::removeAll($stored, 'rolled back');
            throw $error;
        }
    }

    /**
     * Runs the row delete (`$run`, default `$row->delete()`), then removes the files the row owned: its declared
     * columns, the rows it cascades to (recursively), and embedded keys nothing references any more. Keys are read
     * before the delete; a failed delete keeps every file.
     */
    public static function delete(Model $row, ?Closure $run = null): mixed
    {
        $keys = [];
        $embedded = [];
        self::collectOwned($row, $keys, $embedded);
        $result = $run === null ? $row->delete() : $run();
        self::cleanup(function () use ($keys, $embedded): array {
            foreach ($embedded as [$unused, $candidates]) {
                array_push($keys, ...$unused($candidates));
            }

            return $keys;
        }, 'deleted');

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

    /** @param  Closure(): iterable<?string>  $keys */
    private static function cleanup(Closure $keys, string $context): void
    {
        try {
            self::removeAll($keys(), $context);
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

    /**
     * Stores one upload and records its key (and, for variants, the variants) for rollback.
     *
     * @param  list<string>  $stored
     */
    private static function store(string $prefix, mixed $upload, bool $variants, array &$stored): string
    {
        // Image conversion happens here, after the caller's sign-in and ownership checks.
        $file = ImageFile::storedImage($upload);
        $key = Storage::put($prefix, $file);
        $stored[] = $key;
        if ($variants) {
            self::storeVariant($key, $file);
        }

        return $key;
    }

    private static function ownershipOf(Model $row): FileOwnership
    {
        return $row instanceof OwnsFiles ? $row::fileOwnership() : throw new LogicException($row::class.' does not declare the files it owns.');
    }

    /**
     * Keys a write made stale: replaced declared keys, and embedded keys the new value dropped that nothing uses.
     *
     * @param  array<string, mixed>  $before
     * @return list<?string>
     */
    private static function staleKeys(Model $row, FileOwnership $ownership, array $before): array
    {
        $stale = [];
        foreach (array_keys($ownership->columns) as $column) {
            $old = $before[$column] ?? null;
            if ($old !== null && $old !== $row->getAttribute($column)) {
                $stale[] = $old;
            }
        }
        foreach ($ownership->embedded as $column => $embedded) {
            $kept = $embedded['keys']($row->getAttribute($column));
            $dropped = array_values(array_filter($embedded['keys']($before[$column] ?? null), fn (string $ref): bool => ! in_array($ref, $kept, true)));
            array_push($stale, ...$embedded['unused']($dropped));
        }

        return $stale;
    }

    /**
     * @param  list<?string>  $keys
     * @param  list<array{0: Closure, 1: list<string>}>  $embedded  unused-filter and the references it applies to
     */
    private static function collectOwned(Model $row, array &$keys, array &$embedded): void
    {
        $ownership = self::ownershipOf($row);
        foreach (array_keys($ownership->columns) as $column) {
            $keys[] = $row->getAttribute($column);
        }
        foreach ($ownership->embedded as $column => $declared) {
            $embedded[] = [$declared['unused'], $declared['keys']($row->getAttribute($column))];
        }
        foreach ($ownership->cascades as $relation) {
            $query = $row->{$relation}();
            $childOwnership = self::ownershipOf($query->getRelated());
            $columns = $childOwnership->cascades === [] ? $childOwnership->readColumns() : [];
            foreach ($query->get($columns === [] ? ['*'] : $columns) as $child) {
                self::collectOwned($child, $keys, $embedded);
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
