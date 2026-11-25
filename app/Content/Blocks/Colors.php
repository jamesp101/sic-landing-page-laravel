<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use Closure;

/**
 * Block colours. A stored colour is `''` (the block's own colour), a Brand palette name (printed as design-token
 * classes, so it follows dark mode) or a custom `#rrggbb` (printed as an inline style). Twin: `lib/blocks/colors.ts`.
 */
final class Colors
{
    /** Tailwind finds these class names by scanning. */
    public const PALETTE = [
        'primary' => ['label' => 'Primary', 'text' => 'text-primary', 'bg' => 'bg-primary'],
        'navy' => ['label' => 'Navy', 'text' => 'text-brand-800', 'bg' => 'bg-brand-800'],
        'navy-deep' => ['label' => 'Deep navy', 'text' => 'text-brand-950', 'bg' => 'bg-brand-950'],
        'blue' => ['label' => 'Blue', 'text' => 'text-brand-600', 'bg' => 'bg-brand-600'],
        'sky' => ['label' => 'Sky', 'text' => 'text-brand-100', 'bg' => 'bg-brand-100'],
        'sky-soft' => ['label' => 'Soft sky', 'text' => 'text-brand-50', 'bg' => 'bg-brand-50'],
        'gold' => ['label' => 'Gold', 'text' => 'text-gold-500', 'bg' => 'bg-gold-500'],
        'gold-light' => ['label' => 'Light gold', 'text' => 'text-gold-300', 'bg' => 'bg-gold-300'],
        'ink' => ['label' => 'Ink', 'text' => 'text-foreground', 'bg' => 'bg-foreground'],
        'muted' => ['label' => 'Muted', 'text' => 'text-muted-foreground', 'bg' => 'bg-muted-foreground'],
        'surface' => ['label' => 'White', 'text' => 'text-surface', 'bg' => 'bg-surface'],
        'soft' => ['label' => 'Soft grey', 'text' => 'text-surface-alt', 'bg' => 'bg-surface-alt'],
    ];

    public const MESSAGE = 'Pick a palette colour or enter a hex colour such as #0a2e6e.';

    public static function isPalette(mixed $value): bool
    {
        return is_string($value) && array_key_exists($value, self::PALETTE);
    }

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/D', $value) === 1;
    }

    /** `''`, a palette name or `#rrggbb`. */
    public static function isColor(mixed $value): bool
    {
        return $value === '' || self::isPalette($value) || self::isHex($value);
    }

    /**
     * Reports the colour props that are neither empty, a palette name nor a hex colour.
     *
     * @param  array<string, mixed>  $props
     * @param  list<string>  $keys
     */
    public static function check(array $props, array $keys, Closure $report): void
    {
        foreach ($keys as $key) {
            if (! self::isColor($props[$key] ?? null)) {
                $report(self::MESSAGE, [$key]);
            }
        }
    }

    /**
     * How a colour prints: a class or a style declaration; `null` for no (or an invalid) colour.
     *
     * @param  'text'|'bg'  $kind
     * @return array{className: string, style: string}|null
     */
    public static function parts(mixed $value, string $kind): ?array
    {
        if (self::isPalette($value)) {
            return ['className' => self::PALETTE[$value][$kind], 'style' => ''];
        }
        if (self::isHex($value)) {
            return ['className' => '', 'style' => ($kind === 'text' ? 'color' : 'background-color').':'.strtolower($value)];
        }

        return null;
    }

    /**
     * ` style="…"` from the given colour parts, `''` when none of them is a custom colour.
     *
     * @param  array{className: string, style: string}|null  ...$parts
     */
    public static function styleAttr(?array ...$parts): string
    {
        $style = implode(';', array_filter(array_map(static fn (?array $part): string => $part['style'] ?? '', $parts), static fn (string $s): bool => $s !== ''));

        return $style !== '' ? ' style="'.$style.'"' : '';
    }
}
