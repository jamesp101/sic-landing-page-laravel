<?php

namespace App\Storage;

use RuntimeException;

/** A `Range` that starts past the end of the object (or is empty); `$size` is the object's total size. */
final class RangeNotSatisfiableException extends RuntimeException
{
    public function __construct(public readonly int $size)
    {
        parent::__construct('Range not satisfiable');
    }
}
