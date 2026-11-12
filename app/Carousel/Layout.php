<?php

namespace App\Carousel;

/**
 * Mobile layout facts of the carousel: phone breakpoint, 4:5 frame, hero heights, the hero photo `sizes`, and the
 * Layer + box → style rule. The CSS that must repeat them is `resources/css/slide-layout.css`; the client twin is
 * `resources/js/lib/carousel-layout.ts` (the measuring helpers there are browser-only and not ported).
 */
final class Layout
{
    /** Phones are everything narrower than Tailwind's `sm` (40rem), measured on the hero (container) or the viewport (media). */
    public const PHONE_MAX_REM = 39.99;

    /** Below this width the hero is shorter; from it up the hero takes its wide height. */
    public const WIDE_MIN_REM = 48;

    /** The phone frame is 4:5 (width : height). */
    public const PHONE_FRAME = ['w' => 4, 'h' => 5];

    /** The desktop stage is 16:9; layer percentages are of this box. */
    public const DESKTOP_FRAME = ['w' => 16, 'h' => 9];

    /** Tailwind aspect classes of the designer stage per layout. */
    public const FRAME_ASPECT_CLASS = ['desktop' => 'aspect-video', 'phone' => 'aspect-[4/5]'];

    /** Hero heights in px: `tall` is the home page, `short` a Department page; `base` below `WIDE_MIN_REM`, `wide` from it. */
    public const HERO_HEIGHT = [
        'tall' => ['base' => 500, 'wide' => 600],
        'short' => ['base' => 400, 'wide' => 450],
    ];

    /** Screen width (px) the designer's "visible area" guide is drawn for. */
    public const GUIDE_SCREEN_WIDTH = 1440;

    /** Text colours that disappear on the darkened photo of a phone frame: automatically stacked layers switch these to white. */
    private const DARK_ON_PHONE = ['navy', 'navy-deep', 'foreground', 'surface'];

    /**
     * Width (px) the hero photo is drawn at on a phone: `object-cover` fits the 16:9 image to the hero's height, so it
     * is wider than the screen.
     */
    public static function heroPhoneImageWidth(bool $tall): int
    {
        return (int) ceil((self::HERO_HEIGHT[$tall ? 'tall' : 'short']['base'] * self::DESKTOP_FRAME['w']) / self::DESKTOP_FRAME['h']);
    }

    /** `sizes` for a hero photo: the phone width below the phone breakpoint, the viewport otherwise. */
    public static function heroImageSizes(bool $tall): string
    {
        return '(max-width: '.self::number(self::PHONE_MAX_REM).'rem) '.self::heroPhoneImageWidth($tall).'px, 100vw';
    }

    /**
     * Style and classes of one Layer at stacking order `$z` (1 = bottom) with its resolved phone `$box`
     * (`OverlayMobile::resolveMobile`). `$mode`: `public` (desktop box + phone box as custom properties), `desktop`
     * or `phone` (designer previews).
     *
     * @param  array<string, mixed>  $layer
     * @param  array<string, mixed>|null  $box
     * @return array{style: string, phoneClass: string, textColor: string, ghostClass: string}
     */
    public static function layerPresentation(array $layer, int $z, ?array $box, string $mode): array
    {
        $phonePreview = $mode === 'phone' && $box !== null;
        $textColor = self::textColorFor($layer, $box, $mode);
        $parts = ["z-index:{$z}"];

        if ($phonePreview) {
            array_push($parts, 'left:'.self::number($box['x']).'%', 'top:'.self::number($box['y']).'%');
            if (! $box['autoW']) {
                $parts[] = 'width:'.self::number($box['w']).'%';
            }
            if (! $box['autoH']) {
                $parts[] = 'height:'.self::number($box['h']).'%';
            }
            if ($box['fs'] !== null) {
                $parts[] = '--fs:'.self::number($box['fs']);
            }
            // Hidden layers stay reachable in the designer as ghosts; automatic layers are always fully opaque.
            $opacity = $box['hidden'] ? 0.3 : ($box['auto'] ? 1 : $layer['opacity'] / 100);
            if ($opacity < 1) {
                $parts[] = 'opacity:'.self::number($opacity);
            }

            return [
                'style' => implode(';', $parts),
                'phoneClass' => '',
                'textColor' => $textColor,
                'ghostClass' => $box['hidden'] ? 'outline-1 outline-dashed outline-white' : '',
            ];
        }

        array_push($parts, 'left:'.self::number($layer['x']).'%', 'top:'.self::number($layer['y']).'%');
        if (isset($layer['w'])) {
            $parts[] = 'width:'.self::number($layer['w']).'%';
        }
        if (isset($layer['h'])) {
            $parts[] = 'height:'.self::number($layer['h']).'%';
        }
        if ($layer['opacity'] < 100) {
            $parts[] = 'opacity:'.self::number($layer['opacity'] / 100);
        }
        if ($layer['rotation']) {
            $parts[] = 'transform:rotate('.self::number($layer['rotation']).'deg)';
        }
        $kind = Overlay::scaleKind($layer);
        if ($kind !== null && isset($layer['size'])) {
            $parts[] = '--fs:'.self::number(Overlay::TYPE_SCALE[$kind][$layer['size']]);
        }

        $phoneClass = '';
        if ($box !== null && $mode === 'public') {
            array_push($parts, '--mx:'.self::number($box['x']).'%', '--my:'.self::number($box['y']).'%', '--mw:'.($box['autoW'] ? 'auto' : self::number($box['w']).'%'));
            $parts[] = '--mh:'.($box['autoH'] ? 'auto' : self::number($box['h']).'%');
            if ($box['fs'] !== null) {
                $parts[] = '--mfs:'.self::number($box['fs']);
            }
            $phoneClass = $box['hidden'] ? 'sl-mhide' : 'sl-m '.($box['auto'] ? 'sl-mauto' : '');
        }

        return ['style' => implode(';', $parts), 'phoneClass' => $phoneClass, 'textColor' => $textColor, 'ghostClass' => ''];
    }

    /** A number as JavaScript prints it in a template string (shortest form, whole numbers without `.0`). */
    public static function number(int|float $value): string
    {
        if (is_int($value) || ($value == floor($value) && abs($value) < 1e15)) {
            return (string) (int) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private static function textColorFor(array $layer, ?array $box, string $mode): string
    {
        if ($layer['type'] === 'image') {
            return '';
        }
        $base = Overlay::TEXT_COLOR_CLASS[$layer['color']];
        if (! ($box['auto'] ?? false) || ! in_array($layer['color'], self::DARK_ON_PHONE, true)) {
            return $base;
        }
        if ($mode === 'phone') {
            return 'text-white';
        }

        return $mode === 'public' ? "{$base} sl-onphoto" : $base;
    }
}
