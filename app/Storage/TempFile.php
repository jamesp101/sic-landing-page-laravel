<?php

namespace App\Storage;

use Illuminate\Http\UploadedFile;

/** An uploaded-file value backed by a temporary file that is removed when the value is released (converted images, variants). */
final class TempFile extends UploadedFile
{
    public static function fromBytes(string $bytes, string $name, string $mimeType): self
    {
        $path = tempnam(sys_get_temp_dir(), 'sic-');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException('Could not write a temporary file.');
        }

        return new self($path, $name, $mimeType, null, true);
    }

    public function __destruct()
    {
        @unlink($this->getPathname());
    }
}
