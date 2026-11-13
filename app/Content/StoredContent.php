<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Rich content ready to write ({@see Content::forStorage()}): the normalised value for the content column (Block
 * documents re-serialised, empty ones `''`, Legacy Markdown unchanged) and its plain text for `search_text`.
 */
final class StoredContent
{
    public function __construct(
        public readonly string $value,
        public readonly string $searchText,
    ) {}
}
