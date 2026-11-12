<?php

namespace App\Carousel;

use stdClass;

/**
 * Phone layout of a slide: the 4:5 frame, the phone type scale, per-layer overrides and the automatic stacked
 * layout for layers that have no hand-placed position. Pure; the public carousel's server render, the designer
 * canvas (`resources/js/lib/carousel-overlay-mobile.ts`) and the tests all follow the same rule.
 *
 * Geometry is in percent of the 4:5 frame: x/w of its width, y/h of its height. The frame is 1.25 times as tall as
 * it is wide. Text heights are estimated from the text (see `footprint`); the estimate errs on the tall side so
 * stacked layers never touch.
 *
 * A box is `['x', 'y', 'w', 'h', 'autoW', 'autoH', 'fs' (?float cqw), 'hidden', 'auto']`.
 */
final class OverlayMobile
{
    /** Frame height as a multiple of its width (4:5). */
    public const FRAME_H_PER_W = 1.25;

    /** Side margin of auto-stacked layers, percent of the width. */
    public const MOBILE_MARGIN = 6;

    /** Free space kept above the stack (percent of the height). */
    public const MOBILE_TOP = 6;

    /** Free space kept below the stack for the carousel controls (percent of the height). */
    public const MOBILE_BOTTOM = 14;

    public const MOBILE_GAP = 2.5;

    public const MOBILE_GAP_MIN = 1;

    /** Logo height in the automatic layout (percent of the height) and its widest allowed width (of the width). */
    public const MOBILE_LOGO_H = 10;

    public const MOBILE_LOGO_MAX_W = 40;

    /** 12 px on a 320 px wide phone: the smallest text size, in hundredths of the frame width. */
    public const MIN_CQW = 3.75;

    /** Phone type scale in hundredths of the frame width (cqw); every step is at least MIN_CQW. */
    public const MOBILE_TYPE_SCALE = [
        'heading' => ['sm' => 6.6, 'md' => 7.8, 'lg' => 9.2],
        'subtitle' => ['sm' => 4.4, 'md' => 5.0, 'lg' => 5.6],
        'body' => ['sm' => 3.9, 'md' => 4.3, 'lg' => 4.8],
        'button' => ['sm' => 3.8, 'md' => 4.1, 'lg' => 4.5],
        'badge' => ['sm' => 3.75, 'md' => 4.0, 'lg' => 4.3],
    ];

    public const DEFAULT_FOCUS = ['x' => 50, 'y' => 50];

    private const AVERAGE_CHAR_EM = ['heading' => 0.58, 'other' => 0.52];

    private const LINE_HEIGHT = ['heading' => 1.25, 'other' => 1.4];

    /** Tried in order until the stack fits the frame: tighter spacing first, then smaller text. */
    private const LEVELS = [
        ['gap' => 2.5, 'down' => 0, 'scale' => 1],
        ['gap' => 1, 'down' => 0, 'scale' => 1],
        ['gap' => 1, 'down' => 1, 'scale' => 1],
        ['gap' => 1, 'down' => 1, 'scale' => 0.92],
        ['gap' => 1, 'down' => 1, 'scale' => 0.84],
        ['gap' => 1, 'down' => 1, 'scale' => 0.76],
    ];

    /**
     * Where the 16:9 photo is centred when cropped to 4:5.
     *
     * @param  array{focus: array{x: int|float, y: int|float}}|null  $mobile
     * @return array{x: int|float, y: int|float}
     */
    public static function slideFocus(?array $mobile): array
    {
        return $mobile ? $mobile['focus'] : self::DEFAULT_FOCUS;
    }

    /** One step smaller; the smallest step stays. */
    public static function stepDown(string $size, int $steps = 1): string
    {
        return Overlay::SIZES[max(0, array_search($size, Overlay::SIZES, true) - $steps)];
    }

    /** The size step a layer uses on phones: its override, else the desktop step one notch down. Null without a size. */
    public static function mobileSize(array $layer): ?string
    {
        if (! isset($layer['size'])) {
            return null;
        }

        return self::mobileOf($layer)['size'] ?? self::stepDown($layer['size']);
    }

    public static function isPlaced(array $layer): bool
    {
        $mobile = self::mobileOf($layer);

        return isset($mobile['x']) && isset($mobile['y']);
    }

    /** Lines a text needs when `$capacity` characters fit in one line (greedy word wrap, long words break). */
    public static function countLines(string $text, float|int $capacity): int
    {
        $cap = max(1, (int) floor($capacity));
        $lines = 0;
        foreach (explode("\n", $text) as $paragraph) {
            $used = 0;
            $count = 1;
            foreach (preg_split('/'.JsText::WHITESPACE.'+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $length = JsText::utf16Length($word);
                if ($length > $cap) {
                    // A word wider than a line starts a new line and breaks across as many as it needs.
                    $broken = (int) ceil($length / $cap);
                    $count += ($used > 0 ? 1 : 0) + $broken - 1;
                    $used = $length - ($broken - 1) * $cap;
                } elseif ($used === 0) {
                    $used = $length;
                } elseif ($used + 1 + $length <= $cap) {
                    $used += 1 + $length;
                } else {
                    $count++;
                    $used = $length;
                }
            }
            $lines += $count;
        }

        return $lines;
    }

    /**
     * Phone box of every layer, by id.
     *
     * - `hidden` layers are skipped.
     * - Layers with `mobile.x`/`mobile.y` keep exactly that box (`auto: false`).
     * - Every other layer is stacked automatically in reading order: full width inside the side margins (buttons,
     *   badges and logos at their own width), sizes one notch below the desktop step, gaps of `MOBILE_GAP`, the stack
     *   bottom-aligned above the carousel controls. If it does not fit, gaps shrink first, then sizes. Hand-placed
     *   layers are obstacles: a stacked layer that would touch one moves below it.
     * - Panels without a hand-placed box are skipped: they are desktop scrims and the photo gradient takes over.
     *
     * Note: PHP turns numeric-string ids into int keys; look boxes up by `$boxes[$layer['id']]`.
     *
     * @param  list<array<string, mixed>>  $layers
     * @return array<string|int, array<string, mixed>>
     */
    public static function resolveMobile(array $layers): array
    {
        $result = [];
        $obstacles = [];
        $pending = [];

        foreach (Overlay::readingOrder($layers) as ['layer' => $layer]) {
            $mobile = self::mobileOf($layer);
            $placed = self::isPlaced($layer);
            if (! empty($mobile['hidden']) || (! $placed && $layer['type'] === 'shape')) {
                $result[$layer['id']] = ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0, 'autoW' => true, 'autoH' => true, 'fs' => null, 'hidden' => true, 'auto' => ! $placed];

                continue;
            }
            if (! $placed) {
                $pending[] = $layer;

                continue;
            }
            $x = $mobile['x'] ?? 0;
            $y = $mobile['y'] ?? 0;
            $size = self::mobileSize($layer);
            $fs = $size ? self::effective(self::MOBILE_TYPE_SCALE[Overlay::scaleKind($layer)][$size]) : null;
            $fixedW = in_array($layer['type'], ['text', 'shape', 'image'], true);
            $fixedH = in_array($layer['type'], ['shape', 'image'], true);
            $width = $mobile['w'] ?? ($layer['type'] === 'text' ? 100 - 2 * self::MOBILE_MARGIN : ($layer['w'] ?? 0));
            $estimate = self::footprint($layer, $fs, $width);
            $w = $fixedW ? $width : $estimate['w'];
            $h = $fixedH ? ($mobile['h'] ?? ($layer['h'] ?? 0)) : $estimate['h'];
            $result[$layer['id']] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'autoW' => ! $fixedW, 'autoH' => ! $fixedH, 'fs' => $fs, 'hidden' => false, 'auto' => false];
            $obstacles[] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
        }

        if ($pending === []) {
            return $result;
        }

        $best = [];
        foreach (self::LEVELS as $level) {
            $attempt = self::stack($pending, $obstacles, $level);
            $best = $attempt['boxes'];
            if ($attempt['fits']) {
                break;
            }
        }
        foreach ($best as $id => $box) {
            $result[$id] = $box;
        }

        return $result;
    }

    /** @return array<string, mixed> The layer's phone overrides as an array (an empty override is a stdClass). */
    private static function mobileOf(array $layer): array
    {
        $mobile = $layer['mobile'] ?? null;

        return $mobile instanceof stdClass ? (array) $mobile : ($mobile ?? []);
    }

    private static function effective(float|int $fs): float|int
    {
        return max(self::MIN_CQW, $fs);
    }

    private static function toHeight(float|int $widthUnits): float
    {
        return $widthUnits / self::FRAME_H_PER_W;
    }

    /**
     * Estimated width and height (percent of the frame) of a layer rendered at font size `$fs` in a box `$width` wide.
     *
     * @return array{w: float|int, h: float|int}
     */
    private static function footprint(array $layer, float|int|null $fs, float|int $width): array
    {
        $f = self::effective($fs ?? self::MIN_CQW);

        return match ($layer['type']) {
            'text' => (function () use ($layer, $f, $width): array {
                $kind = $layer['role'] === 'heading' ? 'heading' : 'other';
                $em = $width / $f;
                $lines = self::countLines($layer['text'], $em / self::AVERAGE_CHAR_EM[$kind]);

                return ['w' => $width, 'h' => self::toHeight($lines * $f * self::LINE_HEIGHT[$kind])];
            })(),
            'button' => (function () use ($layer, $f): array {
                $em = max(10, JsText::utf16Length($layer['label']) * 0.8 + 5.5);

                return ['w' => min(100 - 2 * self::MOBILE_MARGIN, $em * $f), 'h' => self::toHeight(3 * $f)];
            })(),
            'badge' => ['w' => min(100 - 2 * self::MOBILE_MARGIN, (JsText::utf16Length($layer['text']) * 0.72 + 1.8) * $f), 'h' => self::toHeight(1.8 * $f)],
            default => ['w' => $width, 'h' => 0],
        };
    }

    private static function overlaps(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['w'] && $b['x'] < $a['x'] + $a['w'] && $a['y'] < $b['y'] + $b['h'] && $b['y'] < $a['y'] + $a['h'];
    }

    private static function fontOf(array $layer, ?string $size, array $level): float|int|null
    {
        $kind = Overlay::scaleKind($layer);
        if ($kind === null || $size === null) {
            return null;
        }
        $step = self::stepDown($size, $level['down']);

        return max(self::MIN_CQW, self::MOBILE_TYPE_SCALE[$kind][$step] * $level['scale']);
    }

    /** @return array{boxes: array<string|int, array<string, mixed>>, fits: bool} */
    private static function stack(array $pending, array $obstacles, array $level): array
    {
        $width = 100 - 2 * self::MOBILE_MARGIN;
        $items = array_map(function (array $layer) use ($level, $width): array {
            $fs = self::fontOf($layer, self::mobileSize($layer), $level);
            if ($layer['type'] === 'image') {
                $aspect = ($layer['w'] * 16) / ($layer['h'] * 9);
                $maxH = self::MOBILE_LOGO_H * min(1, $level['scale']);
                $w = min(self::MOBILE_LOGO_MAX_W, $maxH * self::FRAME_H_PER_W * $aspect);

                return ['layer' => $layer, 'fs' => $fs, 'w' => $w, 'h' => $w / (self::FRAME_H_PER_W * $aspect)];
            }

            return ['layer' => $layer, 'fs' => $fs, ...self::footprint($layer, $fs, $width)];
        }, $pending);

        $total = array_sum(array_column($items, 'h')) + $level['gap'] * (count($items) - 1);
        $limit = 100 - self::MOBILE_BOTTOM;
        $cursor = max(self::MOBILE_TOP, $limit - $total);
        $boxes = [];
        $bottom = 0;
        foreach ($items as $item) {
            $rect = ['x' => self::MOBILE_MARGIN, 'y' => $cursor, 'w' => $item['w'], 'h' => $item['h']];
            // Hand-placed layers win: move below any that this one would touch (bounded: each push clears one).
            for ($pass = 0; $pass <= count($obstacles); $pass++) {
                $hit = null;
                foreach ($obstacles as $obstacle) {
                    if (self::overlaps($rect, $obstacle)) {
                        $hit = $obstacle;
                        break;
                    }
                }
                if ($hit === null) {
                    break;
                }
                $rect['y'] = $hit['y'] + $hit['h'] + $level['gap'];
            }
            $layer = $item['layer'];
            $boxes[$layer['id']] = [
                'x' => $rect['x'],
                'y' => $rect['y'],
                'w' => $rect['w'],
                'h' => $rect['h'],
                'autoW' => $layer['type'] !== 'text' && $layer['type'] !== 'image',
                'autoH' => $layer['type'] !== 'image',
                'fs' => $item['fs'],
                'hidden' => false,
                'auto' => true,
            ];
            $cursor = $rect['y'] + $rect['h'] + $level['gap'];
            $bottom = $rect['y'] + $rect['h'];
        }

        return ['boxes' => $boxes, 'fits' => $bottom <= $limit + 0.01];
    }
}
