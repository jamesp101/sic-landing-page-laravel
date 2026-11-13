<?php

namespace App\Storage;

use App\Support\RequestError;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Download intake: accepts the file of a Download from either a multipart `file` (local driver) or an
 * `uploadedKey` the browser sent straight to the bucket (`/api/admin/uploads/direct`), and returns the stored
 * `{ key, size }`. States the Download limits once.
 *
 * A direct upload is verified (it exists and fits) before its pending-upload claim is consumed, so a missing
 * object does not burn the claim; only the user the key was issued to can use it.
 */
final class DownloadIntake
{
    public const DOWNLOAD_MAX_MB = 20;

    public const DOWNLOAD_TOO_LARGE = 'The file must not be larger than 20 MB.';

    public const CHOOSE_FILE = 'Choose a file to upload.';

    public const NO_VIDEOS = 'Videos cannot be uploaded as Downloads.';

    /**
     * Validation rules of the multipart Download file (field-level errors for the upload form): a non-empty file of
     * at most `Storage::limit('download')` bytes that is not a video. Add `'required'` (message `CHOOSE_FILE`) or
     * `'nullable'` in front as the field needs.
     *
     * @return list<ValidationRule>
     */
    public static function downloadFile(): array
    {
        return [new class implements ValidationRule
        {
            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (! $value instanceof UploadedFile) {
                    $fail(DownloadIntake::CHOOSE_FILE);
                } elseif (in_array($value->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    $fail(DownloadIntake::DOWNLOAD_TOO_LARGE);
                } elseif (! $value->isValid() || $value->getSize() <= 0) {
                    $fail(DownloadIntake::CHOOSE_FILE);
                } elseif ($value->getSize() > Storage::limit('download')) {
                    $fail(DownloadIntake::DOWNLOAD_TOO_LARGE);
                } elseif (FileTypes::isVideoUpload($value->getClientOriginalName(), (string) $value->getClientMimeType())) {
                    // Downloads keep their own allowlist: videos go through the video upload.
                    $fail(DownloadIntake::NO_VIDEOS);
                }
            }
        }];
    }

    /** How the browser delivers a Download file: straight to the bucket (`direct`) or as a multipart `file`. */
    public static function downloadUploadMode(): string
    {
        return Storage::directUploads() ? 'direct' : 'multipart';
    }

    /**
     * The accepted file is already stored; the caller removes it (`StoredFiles::save` adopts a key set on a declared file column)
     * if its row write fails.
     *
     * @param  array{file?: ?UploadedFile, uploadedKey?: ?string}  $input
     * @return array{key: string, size: int}
     */
    public static function acceptDownloadFile(int $userId, array $input): array
    {
        $uploadedKey = $input['uploadedKey'] ?? null;
        if ($uploadedKey !== null && $uploadedKey !== '') {
            return PendingUploads::claim(PendingUploads::DOWNLOAD, $userId, $uploadedKey);
        }
        $file = $input['file'] ?? null;
        if (! $file instanceof UploadedFile) {
            throw RequestError::badRequest(self::CHOOSE_FILE);
        }

        return ['key' => Storage::put('downloads', $file), 'size' => (int) $file->getSize()];
    }
}
