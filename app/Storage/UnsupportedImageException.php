<?php

namespace App\Storage;

use RuntimeException;

/** Bytes that decode, but not as one of the allowed raster formats (SVG, TIFF, HEIC… under a `.png` name). */
final class UnsupportedImageException extends RuntimeException
{
    public function __construct(?string $format)
    {
        parent::__construct('Unsupported image format: '.($format ?? 'unknown'));
    }
}
