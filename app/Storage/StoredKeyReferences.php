<?php

namespace App\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The one rule for "which stored keys are referenced, and by which rows": a key is referenced when it appears, as a
 * bare key or inside any URL form (`/storage/<key>`, the S3_PUBLIC_URL form), in ANY string/text column of ANY table
 * (found from the database schema, so a new table or column counts automatically).
 *
 * Two questions use it: `removeReferenced` (storage cleanup's bulk scan, which also counts Pending upload keys as
 * references) and `isReferenced` (a single key, e.g. the Slide video claim, optionally ignoring one row). Pending
 * uploads are not rows that reference for a single-key check: the claimant's own upload would block itself.
 */
final class StoredKeyReferences
{
    private const SCAN_BATCH = 500;

    /** Framework tables: they hold sessions, cache entries and queue payloads, never a stored file's key. */
    private const SKIPPED_TABLES = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    private const TEXT_TYPES = ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'string', 'character varying', 'nvarchar', 'nchar', 'json'];

    /**
     * Every table with its string/text columns, from the database schema.
     *
     * @return list<array{table: string, columns: list<string>, id: bool}>
     */
    public static function stringTables(): array
    {
        $tables = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if ($name === PendingUploads::table() || in_array($name, self::SKIPPED_TABLES, true)) {
                continue;
            }
            $columns = [];
            $hasId = false;
            foreach (Schema::getColumns($name) as $column) {
                if ($column['name'] === 'id') {
                    $hasId = true;
                }
                $type = strtolower((string) ($column['type_name'] ?? ''));
                if (in_array($type, self::TEXT_TYPES, true)) {
                    $columns[] = $column['name'];
                }
            }
            if ($columns !== []) {
                $tables[] = ['table' => $name, 'columns' => $columns, 'id' => $hasId];
            }
        }

        return $tables;
    }

    /**
     * True when `$key` appears in a string column of any table, ignoring the row `$exceptId` of `$exceptTable`
     * (a row never conflicts with itself). One substring query per table; `instr` takes no LIKE wildcards.
     */
    public static function isReferenced(string $key, ?string $exceptTable = null, ?int $exceptId = null): bool
    {
        $tables = self::stringTables();
        if ($tables === []) {
            throw new RuntimeException('Database schema not available: cannot tell whether a stored file is referenced.');
        }
        foreach ($tables as $table) {
            $query = DB::table($table['table']);
            $grammar = $query->getGrammar();
            $query->where(function ($any) use ($table, $grammar, $key): void {
                foreach ($table['columns'] as $column) {
                    $any->orWhereRaw('instr('.$grammar->wrap($column).', ?) > 0', [$key]);
                }
            });
            if ($exceptId !== null && $table['table'] === $exceptTable && $table['id']) {
                $query->where('id', '!=', $exceptId);
            }
            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes from `$unreferenced` every key found in a string column of any table or held by a live Pending upload.
     *
     * @param  array<string, int>  $unreferenced  key => size
     * @param  ?int  $dryRunAt  Dry run: Pending uploads a sweep at this instant (ms) would remove are not counted as references, as the real run sweeps them first.
     */
    public static function removeReferenced(array &$unreferenced, ?int $dryRunAt): void
    {
        $tables = self::stringTables();
        if ($tables === []) {
            throw new RuntimeException('Database schema not available: refusing to clean up.');
        }
        $matcher = new KeyMatcher(array_keys($unreferenced));
        foreach (PendingUploads::livePendingKeys($dryRunAt) as $pendingKey) {
            $matcher->find($pendingKey, function (string $key) use (&$unreferenced): void {
                unset($unreferenced[$key]);
            });
        }
        foreach ($tables as $table) {
            $query = DB::table($table['table'])->select($table['columns']);
            $rows = $table['id'] ? $query->addSelect('id')->lazyById(self::SCAN_BATCH) : $query->cursor();
            foreach ($rows as $row) {
                if ($unreferenced === []) {
                    return;
                }
                foreach ($table['columns'] as $column) {
                    $value = $row->{$column};
                    if (is_string($value) && $value !== '') {
                        $matcher->find($value, function (string $key) use (&$unreferenced): void {
                            unset($unreferenced[$key]);
                        });
                    }
                }
            }
        }
    }
}
