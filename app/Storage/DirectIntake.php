<?php

namespace App\Storage;

use App\Support\RequestError;

/**
 * The one check for an object a browser uploaded straight to storage under a key the server issued (Downloads:
 * `DownloadIntake`, videos: `VideoIntake`).
 *
 * Order matters: owner (the key was issued to this user and has not expired), then the object (exists, not empty,
 * fits), and only then the claim. A missing object therefore does not burn the claim, and an object that does not
 * fit is removed once, by the one user who owned it.
 */
final class DirectIntake
{
    public const INVALID_UPLOAD = 'Invalid uploaded file.';

    /** Throws unless `$key` is well-formed and an unexpired, unclaimed upload issued to `$userId`. Does not consume it. */
    public static function assertIssuedTo(int $userId, string $key, bool $validKey): void
    {
        if (! $validKey || ! PendingUploads::ownsPendingUpload($key, $userId)) {
            throw RequestError::badRequest(self::INVALID_UPLOAD);
        }
    }

    /**
     * Verifies the uploaded object and claims the pending upload (once, by its owner); the object is then kept.
     *
     * `$options`: `validKey` (the caller's check that `$key` has the shape of a key it issues), `maxBytes`,
     * `messages` (`missing`, `empty`, `tooLarge`), `context` (names the rejected object in the cleanup log).
     *
     * @param  array{validKey: bool, maxBytes: int, messages: array{missing: string, empty: string, tooLarge: string}, context: string}  $options
     * @return array{key: string, size: int}
     */
    public static function claimUploadedObject(int $userId, string $key, array $options): array
    {
        self::assertIssuedTo($userId, $key, $options['validKey']);
        $size = Storage::headUpload($key);
        if ($size === null) {
            throw RequestError::badRequest($options['messages']['missing']);
        }
        if ($size <= 0 || $size > $options['maxBytes']) {
            // The claim is consumed so only the owner's rejected object is removed, once.
            if (PendingUploads::claimPendingUpload($key, $userId)) {
                StoredFiles::discardFiles([$key], $options['context']);
            }
            throw RequestError::badRequest($size <= 0 ? $options['messages']['empty'] : $options['messages']['tooLarge']);
        }
        if (! PendingUploads::claimPendingUpload($key, $userId)) {
            throw RequestError::badRequest(self::INVALID_UPLOAD);
        }

        return ['key' => $key, 'size' => $size];
    }
}
