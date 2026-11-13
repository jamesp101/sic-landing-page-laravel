<?php

namespace App\Storage;

use Illuminate\Http\UploadedFile;

/**
 * An uploaded image that passed the cheap checks (declared type, size) and still has to become a WebP for
 * `$target` (an `ImageTargets` name). Conversion decodes the file, so it only runs after the sign-in check: in
 * `StoredFiles::save` and the image intake (`ImageFile::storedImage`), never while the input is validated.
 */
final class ImageUpload extends UploadedFile
{
    public readonly string $target;

    public function __construct(UploadedFile $file, string $target)
    {
        ImageTargets::get($target);
        parent::__construct($file->getPathname(), $file->getClientOriginalName(), $file->getClientMimeType(), null, true);
        $this->target = $target;
    }
}
