<?php

namespace App\Storage;

use RuntimeException;

/** An upload stream delivered more bytes than allowed. */
final class UploadTooLargeException extends RuntimeException
{
    public function __construct(public readonly int $maxBytes)
    {
        parent::__construct("The upload is larger than {$maxBytes} bytes.");
    }
}
