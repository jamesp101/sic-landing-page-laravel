<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use App\Support\Html;

/** What a Block type's `html` needs from the renderer. */
final class RenderContext
{
    /** @param bool $editor Admin canvas: adds editor hooks and placeholders. */
    public function __construct(public readonly bool $editor = false) {}

    /**
     * The `data-block-*` attributes of the canvas (leading space included), `''` on the public site.
     *
     * @param  array<string, mixed>  $block
     */
    public function hooks(array $block, bool $empty = false): string
    {
        if (! $this->editor) {
            return '';
        }
        $label = Registry::get($block['type'])->meta()['label'];

        return ' data-block-id="'.Html::escape($block['id']).'" data-block-type="'.$block['type'].'" data-block-label="'.Html::escape($label).'"'.($empty ? ' data-empty="true"' : '');
    }

    /** Renders child blocks in this context. */
    public function children(?array $children): string
    {
        $html = '';
        foreach ($children ?? [] as $child) {
            $html .= Renderer::renderBlock($child, $this);
        }

        return $html;
    }
}
