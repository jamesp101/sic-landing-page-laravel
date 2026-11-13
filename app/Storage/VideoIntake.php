<?php

namespace App\Storage;

use App\Support\RequestError;

/**
 * Video intake: the checks around a browser video upload (`UploadController::video*`).
 *
 * - `validateVideoUpload` decides whether `{ fileName, contentType, size }` may be issued a key.
 * - `receive` (local driver) streams the PUT body to disk for a key issued to the same user.
 * Granting the key and claiming the upload (step 2) belong to `PendingUploads`.
 */
final class VideoIntake
{
    public const VIDEO_MAX_MB = 100;

    public const VIDEO_TOO_LARGE = 'The video must not be larger than 100 MB.';

    public const VIDEO_TYPE_MESSAGE = 'Choose an MP4 or WebM video.';

    /**
     * Accepts only MP4/WebM: the declared type must be a video type and the extension (from the file name, else
     * the declared type) must be a video extension. The returned `contentType` follows the extension, and is what
     * the upload must be sent as.
     *
     * @param  array{fileName: string, contentType: string, size: int}  $request
     * @return array{ok: true, ext: string, contentType: string}|array{ok: false, message: string}
     */
    public static function validateVideoUpload(array $request, ?int $maxBytes = null): array
    {
        $maxBytes ??= Storage::limit('video');
        if (! in_array($request['contentType'], VideoTypes::TYPES, true)) {
            return ['ok' => false, 'message' => self::VIDEO_TYPE_MESSAGE];
        }
        $stored = FileTypes::storedType($request['fileName'], $request['contentType']);
        if (! isset(VideoTypes::TYPES[$stored['ext']])) {
            return ['ok' => false, 'message' => self::VIDEO_TYPE_MESSAGE];
        }
        if ($request['size'] > $maxBytes) {
            return ['ok' => false, 'message' => self::VIDEO_TOO_LARGE];
        }

        return ['ok' => true, 'ext' => $stored['ext'], 'contentType' => $stored['contentType']];
    }

    /** True for a key `newDirectUploadKey(…, 'videos/')` could have issued, with a video extension. */
    public static function isVideoUploadKey(string $key): bool
    {
        $dot = strrpos($key, '.');

        return DirectUpload::isDirectUploadKey($key, DirectUpload::VIDEO_UPLOAD_PREFIX)
            && $dot !== false && isset(VideoTypes::TYPES[substr($key, $dot)]);
    }

    /**
     * Local driver: streams `$body` to the issued `$key`. Stops and removes the file once more than `$size` (the
     * size declared when the key was issued, at most the video limit) arrive, and removes it again when fewer
     * arrive, so a stored file always has exactly the declared size.
     *
     * @param  resource|null  $body
     * @return array{size: int}
     */
    public static function receive(int $userId, string $key, int $size, $body): array
    {
        PendingUploads::assertIssuedTo($userId, $key, self::isVideoUploadKey($key));
        if ($size <= 0) {
            throw RequestError::badRequest('Choose a file to upload.');
        }
        if ($size > Storage::limit('video')) {
            throw RequestError::badRequest(self::VIDEO_TOO_LARGE);
        }
        if ($body === null) {
            throw RequestError::badRequest('Choose a file to upload.');
        }
        try {
            $written = Storage::receiveUpload($key, $body, $size);
        } catch (UploadTooLargeException) {
            throw RequestError::badRequest('The video is larger than the size it was announced with.');
        }
        if ($written !== $size) {
            StoredFiles::discardFiles([$key], 'incomplete video upload');
            throw RequestError::badRequest('The upload was incomplete. Try again.');
        }

        return ['size' => $written];
    }
}
