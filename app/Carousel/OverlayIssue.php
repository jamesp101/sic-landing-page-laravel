<?php

namespace App\Carousel;

use RuntimeException;

/** The first validation problem of an overlay document; `$layer` is the 0-based index of the layer it is in. */
final class OverlayIssue extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $layer = null)
    {
        parent::__construct($message);
    }

    public function inLayer(int $index): self
    {
        return new self($this->getMessage(), $index);
    }
}
