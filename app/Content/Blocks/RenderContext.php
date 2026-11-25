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

    /**
     * Marks the element holding a text prop as typeable on the canvas (leading space included), `''` on the public
     * site. `$path` is the prop's path (`'title'`, `'items.2.body'`); `rich` keeps bold, italic, links and line breaks;
     * `item` is rich text that is one entry of a string list (`items.<index>`): Enter and pasted lines make new entries.
     *
     * @param  'plain'|'rich'|'item'  $kind
     */
    public function field(string $path, string $kind): string
    {
        return $this->editor ? ' data-edit="'.Html::escape($path).'" data-edit-kind="'.$kind.'"' : '';
    }

    /**
     * Wraps escaped text that shares its element with other markup in a marked `<span>`; the text itself publicly.
     *
     * @param  'plain'|'rich'|'item'  $kind
     */
    public function editable(string $path, string $kind, string $html): string
    {
        return $this->editor ? '<span'.$this->field($path, $kind).'>'.$html.'</span>' : $html;
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
