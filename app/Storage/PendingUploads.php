<?php

namespace App\Storage;

use App\Models\PendingUpload;
use Illuminate\Support\Carbon;

/** Keys issued for a browser upload (Download or video), recorded for the user they were issued to. Times are Unix milliseconds. */
final class PendingUploads
{
    /** How long a key issued for a browser upload may wait to be claimed before it can no longer be. */
    private const CLAIM_TTL_MS = 60 * 60 * 1000;

    /**
     * Sweeping starts this long after expiry, so a sweep can never read a row that a claim racing it at the expiry
     * boundary still deletes successfully.
     */
    private const SWEEP_GRACE_MS = 5 * 60 * 1000;

    private static function at(int $ms): Carbon
    {
        return Carbon::createFromTimestampMs($ms);
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** Records a presigned key as issued to `$userId`. */
    public static function registerPendingUpload(string $key, int $userId, ?int $now = null): void
    {
        PendingUpload::create(['key' => $key, 'user_id' => $userId, 'expires_at' => self::at(($now ?? self::nowMs()) + self::CLAIM_TTL_MS)]);
    }

    /** Claims an unexpired key issued to `$userId`, at most once; false for anyone else's, claimed or expired keys. */
    public static function claimPendingUpload(string $key, int $userId, ?int $now = null): bool
    {
        return PendingUpload::where('key', $key)->where('user_id', $userId)
            ->where('expires_at', '>', self::at($now ?? self::nowMs()))->delete() === 1;
    }

    /** Rows that expired at or before this instant are swept. */
    public static function pendingSweepCutoff(?int $now = null): Carbon
    {
        return self::at(($now ?? self::nowMs()) - self::SWEEP_GRACE_MS);
    }

    /**
     * Deletes expired pending-upload rows (past the sweep grace) and returns how many. Stored objects are never
     * touched: a key that was never completed can still be referenced by content, so only the orphan cleanup
     * (`StorageCleanup`: unreferenced and older than its grace) deletes files.
     */
    public static function sweepPendingUploads(?int $now = null): int
    {
        return PendingUpload::where('expires_at', '<=', self::pendingSweepCutoff($now))->delete();
    }

    /** Expired rows a sweep would remove (past the sweep grace), without removing them. */
    public static function countExpiredPendingUploads(?int $now = null): int
    {
        return PendingUpload::where('expires_at', '<=', self::pendingSweepCutoff($now))->count();
    }

    /** True when `$key` is an unexpired, unclaimed upload issued to `$userId`; does not consume it. */
    public static function ownsPendingUpload(string $key, int $userId, ?int $now = null): bool
    {
        return PendingUpload::where('key', $key)->where('user_id', $userId)
            ->where('expires_at', '>', self::at($now ?? self::nowMs()))->exists();
    }

    /** The table holding the records; `StorageCleanup` reads Pending upload keys through `livePendingKeys`, not the table. */
    public static function table(): string
    {
        return (new PendingUpload)->getTable();
    }

    /**
     * Keys whose Pending upload record still exists, for the cleanup's reference scan (their files must be kept).
     * With `$sweepingAt`, records a sweep at that instant would remove are left out (a dry run that has not swept).
     *
     * @return iterable<string>
     */
    public static function livePendingKeys(?int $sweepingAt = null): iterable
    {
        $query = PendingUpload::query();
        if ($sweepingAt !== null) {
            $query->where('expires_at', '>', self::pendingSweepCutoff($sweepingAt));
        }

        return $query->toBase()->select('key')->pluck('key');
    }
}
