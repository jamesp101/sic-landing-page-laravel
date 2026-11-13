<?php

namespace App\Storage;

use App\Auth\RateLimit;
use App\Models\PendingUpload;
use App\Support\RequestError;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Keys issued for a browser upload (Download file or video), recorded for the user they were issued to. Times are Unix
 * milliseconds.
 *
 * `grant` issues one (quota, key, record, upload target by storage driver); `claim` is the one place a Pending upload
 * is consumed: owner first, then the object (exists, not empty, fits), and only then the claim. A missing object does not
 * burn the claim, and an object that does not fit is removed once, by the one user who owned it.
 */
final class PendingUploads
{
    public const DOWNLOAD = 'download';

    public const VIDEO = 'video';

    public const INVALID_UPLOAD = 'Invalid uploaded file.';

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

    /**
     * Issues a Pending upload of `$kind` to `$userId` from the request's `{ fileName, contentType, size }`: checks the
     * kind's type and size limits, the user's upload quota, then records a server-generated key and says where to PUT
     * the bytes (a presigned bucket URL, or for videos on the local driver the app's own `videoBytes` route).
     *
     * Download: `{ key, url }`, only with direct uploads enabled. Video: `{ key, url, contentType }`, where
     * `contentType` is the type the PUT must send.
     *
     * @return array{key: string, url: string}|array{key: string, url: string, contentType: string}
     */
    public static function grant(string $kind, int $userId, Request $request): array
    {
        if ($kind === self::DOWNLOAD && ! Storage::directUploads()) {
            throw RequestError::notFound('Direct uploads are not enabled.');
        }
        $upload = UploadRequest::readUploadRequest($request);

        if ($kind === self::VIDEO) {
            $checked = VideoIntake::validateVideoUpload($upload);
            if (! $checked['ok']) {
                throw RequestError::unprocessable($checked['message']);
            }
            $contentType = $checked['contentType'];
        } else {
            // Downloads keep their own allowlist: every file type except videos, which only the video upload accepts.
            if (! FileTypes::isAllowedContentType($upload['contentType']) || FileTypes::isVideoUpload($upload['fileName'], $upload['contentType'])) {
                throw RequestError::unprocessable('This file type is not allowed.');
            }
            if ($upload['size'] > Storage::limit('download')) {
                throw RequestError::unprocessable(DownloadIntake::DOWNLOAD_TOO_LARGE);
            }
            $contentType = $upload['contentType'];
        }
        RateLimit::assertUploadQuota($userId);

        $key = $kind === self::VIDEO
            ? DirectUpload::newDirectUploadKey($upload['fileName'], $contentType, DirectUpload::VIDEO_UPLOAD_PREFIX)
            : DirectUpload::newDirectUploadKey($upload['fileName'], $contentType);
        self::registerPendingUpload($key, $userId);

        if (Storage::directUploads()) {
            $url = Storage::presignUpload($key, $contentType, $upload['size']);
        } else {
            $url = '/api/admin/uploads/video?'.http_build_query(['key' => $key, 'size' => (string) $upload['size']], '', '&', PHP_QUERY_RFC1738);
        }

        return $kind === self::VIDEO
            ? ['key' => $key, 'url' => $url, 'contentType' => $contentType]
            : ['key' => $key, 'url' => $url];
    }

    /** Throws unless `$key` is well-formed and an unexpired, unclaimed upload issued to `$userId`. Does not consume it. */
    public static function assertIssuedTo(int $userId, string $key, bool $validKey): void
    {
        if (! $validKey || ! self::ownsPendingUpload($key, $userId)) {
            throw RequestError::badRequest(self::INVALID_UPLOAD);
        }
    }

    /**
     * Verifies the object a browser uploaded under `$key` and claims the Pending upload (once, by its owner); the object
     * is then kept. An object that is empty or too large is removed (by the owner only) and the claim consumed.
     *
     * @return array{key: string, size: int}
     */
    public static function claim(string $kind, int $userId, string $key): array
    {
        $video = $kind === self::VIDEO;
        $maxBytes = Storage::limit($video ? 'video' : 'download');
        self::assertIssuedTo($userId, $key, $video ? VideoIntake::isVideoUploadKey($key) : Storage::directUploads() && DirectUpload::isDirectUploadKey($key));

        $size = Storage::headUpload($key);
        if ($size === null) {
            throw RequestError::badRequest($video ? 'The uploaded video was not found. Upload it again.' : 'The uploaded file was not found. Upload it again.');
        }
        if ($size <= 0 || $size > $maxBytes) {
            // The claim is consumed so only the owner's rejected object is removed, once.
            if (self::claimPendingUpload($key, $userId)) {
                StoredFiles::discardFiles([$key], $video ? 'rejected video upload' : 'rejected upload');
            }
            if ($size <= 0) {
                throw RequestError::badRequest($video ? 'The uploaded video is empty.' : 'The uploaded file is empty.');
            }
            throw RequestError::badRequest($video ? VideoIntake::VIDEO_TOO_LARGE : DownloadIntake::DOWNLOAD_TOO_LARGE);
        }
        if (! self::claimPendingUpload($key, $userId)) {
            throw RequestError::badRequest(self::INVALID_UPLOAD);
        }

        return ['key' => $key, 'size' => $size];
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
