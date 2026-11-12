<?php

declare(strict_types=1);

namespace App\Content\Blocks;

/** Block document constants and the hard limits enforced on save. */
final class Limits
{
    public const DOCUMENT_TYPE = 'sic-blocks';

    public const DOCUMENT_VERSION = 1;

    public const DOCUMENT_CHARS = 400_000;

    public const BLOCKS = 400;

    public const DEPTH = 6;

    public const TEXT = 5_000;

    public const LABEL = 300;

    public const URL = 2_000;

    public const LIST_ITEMS = 50;

    public const TABLE_ROWS = 60;

    public const TABLE_COLUMNS = 10;

    public const CELL = 500;

    public const ACCORDION_ITEMS = 30;

    public const STAT_ITEMS = 12;

    public const BADGE_ITEMS = 20;

    public const MARKDOWN = 100_000;

    public const ALBUM_IMAGES = 48;
}
