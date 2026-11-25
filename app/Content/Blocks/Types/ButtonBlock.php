<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\Limits;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\RenderKit;
use App\Content\Blocks\Urls;
use App\Content\Blocks\Z;
use App\Content\Js;
use Closure;
use stdClass;

final class ButtonBlock extends BlockType
{
    private const JUSTIFY_SELF = ['inherit' => 'justify-start', 'left' => 'justify-start', 'center' => 'justify-center', 'right' => 'justify-end'];

    private const BASE = 'inline-flex min-h-11 items-center justify-center rounded-lg px-5 py-2.5 text-sm font-semibold transition-colors duration-200 ease-out';

    private const VARIANT = [
        'primary' => self::BASE.' bg-brand-800 text-white shadow-brand-sm hover:bg-brand-700 dark:bg-gold-500 dark:text-brand-950 dark:hover:bg-gold-400',
        'secondary' => self::BASE.' border border-border-strong bg-surface text-foreground shadow-brand-sm hover:bg-surface-alt',
        'ghost' => self::BASE.' text-link hover:bg-accent',
    ];

    public function meta(): array
    {
        return ['label' => 'Button', 'group' => 'Content', 'description' => 'Link styled as a button.'];
    }

    public function defaults(): array
    {
        return ['label' => 'Learn more', 'href' => '/', 'variant' => 'primary', 'align' => 'left'];
    }

    public function schema(): array
    {
        return Z::object([
            'label' => Z::str(Limits::LABEL),
            'href' => Z::str(Limits::URL),
            'variant' => Z::enum(['primary', 'secondary', 'ghost'], 'primary'),
            'align' => Z::enum(['inherit', 'left', 'center', 'right'], 'inherit'),
        ]);
    }

    public function rules(array $props, Closure $report): void
    {
        if (Js::blank($props['label'])) {
            $report('Give the button a label.', ['label']);
        }
        if (Urls::safeHref($props['href']) === null) {
            $report('Enter a valid link (https://, /path, mailto: or tel:).', ['href']);
        }
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $p = $block['props'];
        $href = Urls::safeHref($p['href']);
        if (Js::blank($p['label']) || $href === null) {
            return '';
        }
        $external = Urls::isExternalHref($href) ? ' target="_blank" rel="noopener noreferrer"' : '';

        return '<div class="flex '.RenderKit::pick(self::JUSTIFY_SELF, $p['align'], 'left').'"'.$ctx->hooks($block).'><a href="'.RenderKit::esc($href).'" class="'.RenderKit::pick(self::VARIANT, $p['variant'], 'primary').'"'.$external.$ctx->field('label', 'plain').'>'.RenderKit::esc($p['label']).'</a></div>';
    }

    public function text(stdClass $props): array
    {
        return [];
    }
}
