<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use App\Content\Blocks\Types\AccordionBlock;
use App\Content\Blocks\Types\AlbumBlock;
use App\Content\Blocks\Types\BadgesBlock;
use App\Content\Blocks\Types\ButtonBlock;
use App\Content\Blocks\Types\CalloutBlock;
use App\Content\Blocks\Types\CardBlock;
use App\Content\Blocks\Types\ColumnsBlock;
use App\Content\Blocks\Types\DividerBlock;
use App\Content\Blocks\Types\EventBlock;
use App\Content\Blocks\Types\GridBlock;
use App\Content\Blocks\Types\HeadingBlock;
use App\Content\Blocks\Types\ImageBlock;
use App\Content\Blocks\Types\ListBlock;
use App\Content\Blocks\Types\MarkdownBlock;
use App\Content\Blocks\Types\ParagraphBlock;
use App\Content\Blocks\Types\QuoteBlock;
use App\Content\Blocks\Types\RowBlock;
use App\Content\Blocks\Types\SectionBlock;
use App\Content\Blocks\Types\SpacerBlock;
use App\Content\Blocks\Types\StatsBlock;
use App\Content\Blocks\Types\TableBlock;
use App\Content\Blocks\Types\VideoBlock;

/**
 * The Block type registry: one class per Block type (`Types/`), looked up by validation, rendering and
 * plain-text extraction. Order is the palette order.
 */
final class Registry
{
    /** @var array<string, class-string<BlockType>> */
    private const CLASSES = [
        'section' => SectionBlock::class,
        'row' => RowBlock::class,
        'grid' => GridBlock::class,
        'columns' => ColumnsBlock::class,
        'spacer' => SpacerBlock::class,
        'divider' => DividerBlock::class,
        'heading' => HeadingBlock::class,
        'paragraph' => ParagraphBlock::class,
        'list' => ListBlock::class,
        'quote' => QuoteBlock::class,
        'button' => ButtonBlock::class,
        'badges' => BadgesBlock::class,
        'card' => CardBlock::class,
        'image' => ImageBlock::class,
        'album' => AlbumBlock::class,
        'table' => TableBlock::class,
        'accordion' => AccordionBlock::class,
        'callout' => CalloutBlock::class,
        'stats' => StatsBlock::class,
        'video' => VideoBlock::class,
        'event' => EventBlock::class,
        'markdown' => MarkdownBlock::class,
    ];

    /** @var array<string, BlockType> */
    private static array $instances = [];

    /** Every Block type in palette order. @return list<string> */
    public static function types(): array
    {
        return array_keys(self::CLASSES);
    }

    public static function isBlockType(mixed $type): bool
    {
        return is_string($type) && array_key_exists($type, self::CLASSES);
    }

    public static function isContainerType(string $type): bool
    {
        return self::get($type)->container();
    }

    public static function get(string $type): BlockType
    {
        return self::$instances[$type] ??= new (self::CLASSES[$type]);
    }
}
