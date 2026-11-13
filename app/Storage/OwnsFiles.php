<?php

namespace App\Storage;

/** A model whose rows own stored files; `StoredFiles` works out owned, stale and cascaded keys from the declaration. */
interface OwnsFiles
{
    public static function fileOwnership(): FileOwnership;
}
