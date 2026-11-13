<?php

namespace App\Storage;

/**
 * Storage cleanup: deletes stored files that no database row references and that are older than a grace period,
 * plus expired pending-upload rows (never their files directly).
 *
 * "Referenced" is `StoredKeyReferences`' rule (any string column of any table, plus live Pending uploads). Files
 * younger than the grace period are never touched, so an upload whose row is not committed yet is safe.
 *
 * An image variant (`Variants`) is never referenced by a row: it lives and dies with its original. While the
 * original is stored, the variant is neither a candidate nor counted; an unreferenced original is deleted together
 * with its variant (reported as one file); a variant whose original is gone is an ordinary unreferenced file.
 */
final class StorageCleanup
{
    public const GRACE_MS = 24 * 60 * 60 * 1000;

    private const DEFAULT_MAX_DELETES = 1000;

    private const DEFAULT_BUDGET_MS = 40_000;

    private const DELETE_CHUNK = 25;

    private const SWEEP_ROUNDS = 20;

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * Deletes (or, in a dry run, lists) unreferenced old files and sweeps expired pending uploads.
     *
     * `$options`: `dryRun`, `now` (ms), `graceMs`, `maxDeletes` (most files deleted in one run; the next run
     * continues), `budgetMs` (wall-clock budget for deleting).
     *
     * @param  array{dryRun?: bool, now?: int, graceMs?: int, maxDeletes?: int, budgetMs?: int}  $options
     * @return array{dryRun: bool, listed: int, candidates: int, referenced: int, remaining: int, deleted: int, bytesFreed: int, pendingUploads: int, keys: list<string>}
     */
    public static function cleanupStorage(array $options = []): array
    {
        $startedAt = self::nowMs();
        $now = $options['now'] ?? $startedAt;
        $dryRun = $options['dryRun'] ?? false;
        $cutoff = $now - ($options['graceMs'] ?? self::GRACE_MS);
        $maxDeletes = $options['maxDeletes'] ?? self::DEFAULT_MAX_DELETES;
        $budgetMs = $options['budgetMs'] ?? self::DEFAULT_BUDGET_MS;
        $timeLeft = fn (): int => $startedAt + $budgetMs - self::nowMs();

        // Expired pending uploads go first: their rows (which reference the key) disappear with them.
        $pendingUploads = 0;
        if ($dryRun) {
            $pendingUploads = PendingUploads::countExpiredPendingUploads($now);
        } else {
            for ($round = 0; $round < self::SWEEP_ROUNDS && $timeLeft() > 0; $round++) {
                $swept = PendingUploads::sweepPendingUploads($now);
                if ($swept === 0) {
                    break;
                }
                $pendingUploads += $swept;
            }
        }

        $listed = 0;
        /** @var array<string, array{size: int, mtime: int}> $listedObjects */
        $listedObjects = [];
        foreach (Storage::list() as $object) {
            $listed++;
            $listedObjects[$object['key']] = ['size' => $object['size'], 'mtime' => $object['mtime'] * 1000];
        }
        // Variants that have a stored original travel with it; the others are judged like any file.
        $originalBases = [];
        foreach (array_keys($listedObjects) as $key) {
            if (! Variants::isVariantKey($key)) {
                $originalBases[Variants::variantBase($key)] = true;
            }
        }
        $candidates = [];
        $variantsOf = [];
        foreach ($listedObjects as $key => $info) {
            $key = (string) $key;
            if (Variants::isVariantKey($key) && isset($originalBases[Variants::variantBase($key)])) {
                continue;
            }
            if ($info['mtime'] <= $cutoff) {
                $candidates[$key] = $info;
            }
            $variants = array_values(array_filter(Variants::variantKeysOf($key), fn (string $variant) => isset($listedObjects[$variant])));
            if ($variants !== []) {
                $variantsOf[$key] = $variants;
            }
        }
        $candidateCount = count($candidates);

        $unreferenced = array_map(fn (array $info) => $info['size'], $candidates);
        StoredKeyReferences::removeReferenced($unreferenced, $dryRun ? $now : null);

        $ordered = array_keys($unreferenced);
        usort($ordered, fn (string $a, string $b) => $candidates[$a]['mtime'] <=> $candidates[$b]['mtime']);
        $keys = [];
        $bytesFreed = 0;
        for ($i = 0, $n = count($ordered); $i < $n && count($keys) < $maxDeletes; $i += self::DELETE_CHUNK) {
            if (! $dryRun && $timeLeft() <= 0) {
                break;
            }
            $chunk = array_slice($ordered, $i, min(self::DELETE_CHUNK, $maxDeletes - count($keys)));
            $variants = array_merge(...array_map(fn (string $key) => $variantsOf[$key] ?? [], $chunk));
            if (! $dryRun) {
                StoredFiles::discardFiles([...$chunk, ...$variants], 'unreferenced file');
            }
            array_push($keys, ...$chunk);
            foreach ($chunk as $key) {
                $bytesFreed += $unreferenced[$key] ?? 0;
            }
            foreach ($variants as $key) {
                $bytesFreed += $listedObjects[$key]['size'] ?? 0;
            }
        }

        return [
            'dryRun' => $dryRun,
            'listed' => $listed,
            'candidates' => $candidateCount,
            'referenced' => $candidateCount - count($unreferenced),
            'remaining' => count($ordered) - count($keys),
            'deleted' => count($keys),
            'bytesFreed' => $bytesFreed,
            'pendingUploads' => $pendingUploads,
            'keys' => $keys,
        ];
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%.1f MB', $bytes / (1024 * 1024));
    }

    /**
     * Human-readable summary; a dry run lists the keys.
     *
     * @param  array<string, mixed>  $report
     */
    public static function formatCleanupReport(array $report): string
    {
        $verb = $report['dryRun'] ? 'Would delete' : 'Deleted';
        $lines = [
            "Stored files: {$report['listed']} ({$report['candidates']} older than the grace period, {$report['referenced']} still referenced)",
            "{$verb} {$report['deleted']} unreferenced file(s), ".self::formatBytes($report['bytesFreed']),
            ($report['dryRun'] ? 'Expired pending uploads found' : 'Expired pending uploads swept').": {$report['pendingUploads']}",
        ];
        if ($report['remaining'] > 0) {
            $lines[] = "Left for the next run: {$report['remaining']}";
        }
        if ($report['dryRun']) {
            array_push($lines, ...array_map(fn (string $key) => "  {$key}", $report['keys']));
        }

        return implode("\n", $lines);
    }
}
