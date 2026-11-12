<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use Closure;
use stdClass;

/**
 * One Block type: defaults, props schema, rules, HTML and plain text. Every type is a class in `Types/`
 * listed once in {@see Registry}, whose order is the palette order.
 */
abstract class BlockType
{
    /** @return array{label: string, group: string, description: string} */
    abstract public function meta(): array;

    /** Holds child blocks. */
    public function container(): bool
    {
        return false;
    }

    /** Can only be placed at the top level of a document. */
    public function rootOnly(): bool
    {
        return false;
    }

    /** Props of a freshly created block. */
    abstract public function defaults(): array;

    /** Shape of the props ({@see Z::object()}): whitelisted values, limits, defaults for missing props. */
    abstract public function schema(): array;

    /**
     * Cross-field rules that need more than a shape check; run on schema-normalised props.
     *
     * @param  Closure(string, list<string|int>=): void  $report
     */
    public function rules(array $props, Closure $report): void {}

    /**
     * Public HTML (or the canvas HTML when `$ctx->editor`); every string escaped, every URL filtered.
     *
     * @param  array<string, mixed>  $block
     */
    abstract public function html(array $block, RenderContext $ctx): string;

    /**
     * Readable texts for search and excerpts, from stored props of unknown shape. Tolerates anything.
     *
     * @return list<string>
     */
    abstract public function text(stdClass $props): array;
}
