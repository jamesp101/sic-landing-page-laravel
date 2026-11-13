<?php

namespace App\Storage;

use Closure;

/**
 * What a model's rows own in storage, declared once per model (see {@see OwnsFiles}):
 *
 * - `columns`: column => `['prefix' => directory of new files, 'variants' => bool]`. The column holds a bare stored
 *   key; `variants` stores (and removes) the Image variants with the original.
 * - `cascades`: relation names whose rows are deleted with this row (database cascade), so their files go too.
 * - `embedded`: column => `['keys' => fn(?string $value): list<string>, 'unused' => fn(list<string>): list<string>]`.
 *   Keys referenced from inside a column's value (a Slide's Layer images in its Overlay). They are owned only
 *   while no other row references them: `keys` lists what a value references, `unused` keeps those nothing
 *   references any more (asked after the commit).
 */
final class FileOwnership
{
    /**
     * @param  array<string, array{prefix: string, variants: bool}>  $columns
     * @param  list<string>  $cascades
     * @param  array<string, array{keys: Closure, unused: Closure}>  $embedded
     */
    public function __construct(
        public readonly array $columns = [],
        public readonly array $cascades = [],
        public readonly array $embedded = [],
    ) {}

    /** Every column `StoredFiles` has to read from a row. @return list<string> */
    public function readColumns(): array
    {
        return [...array_keys($this->columns), ...array_keys($this->embedded)];
    }
}
