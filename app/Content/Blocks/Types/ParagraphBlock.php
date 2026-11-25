<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\Colors;
use App\Content\Blocks\Inline;
use App\Content\Blocks\Limits;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\RenderKit;
use App\Content\Blocks\TextKit;
use App\Content\Blocks\Z;
use App\Content\Js;
use Closure;
use stdClass;

final class ParagraphBlock extends BlockType
{
    private const SIZE = ['sm' => 'text-sm', 'base' => 'text-base', 'lg' => 'text-lg'];

    private const TONE = ['default' => 'text-foreground', 'muted' => 'text-muted-foreground'];

    public function meta(): array
    {
        return ['label' => 'Text', 'group' => 'Content', 'description' => 'Paragraph with bold, italic and links.'];
    }

    public function defaults(): array
    {
        return ['text' => 'Write something here.', 'size' => 'base', 'tone' => 'default', 'align' => 'inherit', 'color' => '', 'background' => ''];
    }

    public function schema(): array
    {
        return Z::object([
            'text' => Z::str(Limits::TEXT),
            'size' => Z::enum(['sm', 'base', 'lg'], 'base'),
            'tone' => Z::enum(['default', 'muted'], 'default'),
            'align' => Z::enum(['inherit', 'left', 'center', 'right', 'justify'], 'inherit'),
            'color' => Z::str(Limits::LABEL),
            'background' => Z::str(Limits::LABEL),
        ]);
    }

    public function rules(array $props, Closure $report): void
    {
        Colors::check($props, ['color', 'background'], $report);
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $p = $block['props'];
        if (Js::blank($p['text'])) {
            return $ctx->editor ? '<p class="text-base text-muted-foreground"'.$ctx->hooks($block).$ctx->field('text', 'rich').'>&nbsp;</p>' : '';
        }
        $text = Colors::parts($p['color'], 'text');
        $bg = Colors::parts($p['background'], 'bg');
        $classes = RenderKit::joinClasses(
            'leading-relaxed',
            RenderKit::pick(self::SIZE, $p['size'], 'base'),
            $text !== null ? $text['className'] : RenderKit::pick(self::TONE, $p['tone'], 'default'),
            RenderKit::pick(RenderKit::TEXT_ALIGN, $p['align'], 'inherit'),
            $bg !== null ? RenderKit::joinClasses('rounded-lg px-4 py-3', $bg['className']) : null,
        );

        return '<p class="'.$classes.'"'.Colors::styleAttr($text, $bg).$ctx->hooks($block).$ctx->field('text', 'rich').'>'.Inline::inlineToHtml($p['text']).'</p>';
    }

    public function text(stdClass $props): array
    {
        return [TextKit::richOf($props->text ?? null)];
    }
}
