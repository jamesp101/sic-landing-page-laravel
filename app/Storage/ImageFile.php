<?php

namespace App\Storage;

use App\Support\RequestError;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Raster image input: only the declared type and size are checked while validating (nothing is decoded, so a
 * signed-out or failing request costs nothing). `StoredFiles::save` and the image intake then call
 * `storedImage`, which re-encodes an `ImageUpload` as WebP for its target, so every stored image upload is WebP.
 */
final class ImageFile
{
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];

    /** Message for a missing or non-file value; use it as the `required` message of the field. */
    public const MISSING = 'Choose an image file.';

    public const BAD_TYPE = 'The file must be a JPG, PNG, WebP, GIF or AVIF image.';

    public static function tooLarge(int $maxBytes): string
    {
        return 'The image must not be larger than '.round($maxBytes / Storage::MB).' MB.';
    }

    /**
     * Validation rules for an uploaded raster image of at most `$maxBytes`: type and size only. Add `'required'`
     * (message `ImageFile::MISSING`) or `'nullable'` in front as the field needs. After validating, wrap the file
     * with `upload()` before handing it to `StoredFiles::save`.
     *
     * @return list<ValidationRule>
     */
    public static function imageFile(int $maxBytes, string $target): array
    {
        ImageTargets::get($target);

        return [new class($maxBytes) implements ValidationRule
        {
            public function __construct(private readonly int $maxBytes) {}

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (! $value instanceof UploadedFile) {
                    $fail(ImageFile::MISSING);
                } elseif (in_array($value->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    $fail(ImageFile::tooLarge($this->maxBytes));
                } elseif (! $value->isValid()) {
                    $fail(ImageFile::MISSING);
                } elseif (! in_array($value->getClientMimeType(), ImageFile::IMAGE_TYPES, true)) {
                    $fail(ImageFile::BAD_TYPE);
                } elseif ($value->getSize() > $this->maxBytes) {
                    $fail(ImageFile::tooLarge($this->maxBytes));
                }
            }
        }];
    }

    /** The validated file as an `ImageUpload` for `$target`. */
    public static function upload(UploadedFile $file, string $target): ImageUpload
    {
        return new ImageUpload($file, $target);
    }

    /**
     * The file to store: an `ImageUpload` becomes its WebP (the real format is checked there, not the declared
     * one); any other file is returned as is. Throws RequestError BAD_REQUEST for an unreadable or disallowed image.
     */
    public static function storedImage(UploadedFile $file): UploadedFile
    {
        if (! $file instanceof ImageUpload) {
            return $file;
        }
        try {
            return ImageWebp::toWebp($file, $file->target);
        } catch (Throwable) {
            throw RequestError::badRequest('The image could not be read. Choose another file.');
        }
    }
}
