<?php

declare(strict_types=1);

namespace App\Content\Blocks;

/**
 * Block document → semantic HTML. Every string is escaped, every URL is filtered, every colour is a design
 * token and headings are clamped to h2–h4 (the page owns its single h1). Each Block type renders itself;
 * this class walks the tree. The output equals the TS renderer's (`resources/js/lib/blocks/render.ts`), which
 * the admin canvas runs client-side.
 */
final class Renderer
{
    /**
     * Renders a list of validated blocks inside the standard vertical flow wrapper; `''` when nothing renders.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array{editor?: bool}  $options  `editor` adds `data-block-*` hooks, drop-target hints and video placeholders
     */
    public static function renderBlocks(array $blocks, array $options = []): string
    {
        $inner = (new RenderContext(($options['editor'] ?? false) === true))->children($blocks);

        return $inner !== '' ? '<div class="flex flex-col gap-5" data-sic-blocks>'.$inner.'</div>' : '';
    }

    /** @param array<string, mixed> $block */
    public static function renderBlock(array $block, RenderContext $ctx): string
    {
        if (! Registry::isBlockType($block['type'] ?? null)) {
            return '';
        }
        $html = Registry::get($block['type'])->html($block, $ctx);
        if ($html !== '' || ! $ctx->editor) {
            return $html;
        }

        // Blocks that render nothing publicly (e.g. an empty heading) still need a canvas element so they can be selected.
        return '<div class="rounded-lg border border-dashed border-border-strong px-3 py-2 text-sm text-muted-foreground"'.$ctx->hooks($block).'>Empty '.RenderKit::esc(mb_strtolower(Registry::get($block['type'])->meta()['label'])).'. Fill it in using the inspector.</div>';
    }
}
