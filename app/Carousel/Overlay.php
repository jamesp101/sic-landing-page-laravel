<?php

namespace App\Carousel;

use stdClass;

/**
 * Carousel slide overlay model: a versioned JSON document stored in `carousel_items.overlay`.
 *
 * `{ "v": 1, "layers": [Layer, ...], "mobile"?: SlideMobile }`. Layer array order is the z-order (last = on top).
 * Positions and sizes are percentages of the 16:9 slide (x/w of its width, y/h of its height); text sizes are
 * named steps (see TYPE_SCALE). A layer may carry `mobile` overrides (percent of the 4:5 phone frame, a size step,
 * hidden); `SlideMobile` holds the slide-level phone settings (photo focal point). Documents without any `mobile`
 * data parse unchanged.
 *
 * Layers are associative arrays with the camelCase keys of `resources/js/lib/carousel-overlay.ts`; an empty
 * `mobile` override is a stdClass so it stays a JSON object. Validation lives in OverlaySchema.
 */
final class Overlay
{
    public const OVERLAY_VERSION = 1;

    public const MAX_LAYERS = 24;

    public const MAX_OVERLAY_JSON = 40_000;

    public const LAYER_TYPES = ['text', 'button', 'badge', 'shape', 'image'];

    public const ROLES = ['heading', 'subtitle', 'body'];

    public const SIZES = ['sm', 'md', 'lg'];

    public const COLORS = ['white', 'gold', 'navy', 'navy-deep', 'sky', 'foreground', 'surface'];

    public const ALIGNS = ['left', 'center', 'right'];

    public const FONTS = ['serif', 'sans'];

    public const RADII = ['none', 'sm', 'md', 'lg', 'full'];

    public const BUTTON_VARIANTS = ['solid', 'outline'];

    /** Font size per element kind and step, in hundredths of the slide width. */
    public const TYPE_SCALE = [
        'heading' => ['sm' => 3.4, 'md' => 4.6, 'lg' => 6],
        'subtitle' => ['sm' => 1.8, 'md' => 2.2, 'lg' => 2.6],
        'body' => ['sm' => 1.4, 'md' => 1.6, 'lg' => 1.8],
        'button' => ['sm' => 1.2, 'md' => 1.4, 'lg' => 1.6],
        'badge' => ['sm' => 1.0, 'md' => 1.1, 'lg' => 1.3],
    ];

    public const DEFAULT_SIZE = [
        'heading' => 'lg',
        'subtitle' => 'md',
        'body' => 'md',
        'button' => 'md',
        'badge' => 'sm',
    ];

    public const COLOR_LABEL = [
        'white' => 'White',
        'gold' => 'Gold',
        'navy' => 'Navy',
        'navy-deep' => 'Deep navy',
        'sky' => 'Sky',
        'foreground' => 'Ink (theme text)',
        'surface' => 'Paper (theme surface)',
    ];

    /* Class maps are literal strings so Tailwind can see them (Tailwind scans app/ for them). */
    public const TEXT_COLOR_CLASS = [
        'white' => 'text-white',
        'gold' => 'text-gold-300',
        'navy' => 'text-brand-800',
        'navy-deep' => 'text-brand-950',
        'sky' => 'text-brand-100',
        'foreground' => 'text-foreground',
        'surface' => 'text-surface',
    ];

    public const BG_COLOR_CLASS = [
        'white' => 'bg-white',
        'gold' => 'bg-gold-500',
        'navy' => 'bg-brand-800',
        'navy-deep' => 'bg-brand-950',
        'sky' => 'bg-brand-100',
        'foreground' => 'bg-foreground',
        'surface' => 'bg-surface',
    ];

    /** Readable text colour on top of each background colour (buttons and badges). */
    public const ON_COLOR_CLASS = [
        'white' => 'text-brand-950',
        'gold' => 'text-brand-950',
        'navy' => 'text-white',
        'navy-deep' => 'text-white',
        'sky' => 'text-brand-950',
        'foreground' => 'text-surface',
        'surface' => 'text-foreground',
    ];

    public const RADIUS_CLASS = [
        'none' => 'rounded-none',
        'sm' => 'rounded-md',
        'md' => 'rounded-xl',
        'lg' => 'rounded-3xl',
        'full' => 'rounded-full',
    ];

    public const ALIGN_CLASS = [
        'left' => 'text-left',
        'center' => 'text-center',
        'right' => 'text-right',
    ];

    /** Safe-area margin (percent) kept free at every slide edge by default alignment. */
    public const SAFE_MARGIN = 6;

    /** Sizes stored by the first designer version (xs…3xl) in hundredths of the slide width. */
    private const LEGACY_CQW = ['xs' => 1.1, 'sm' => 1.4, 'md' => 1.8, 'lg' => 2.4, 'xl' => 3.4, '2xl' => 4.6, '3xl' => 6];

    private const UNSAFE_CHARS = '/[\x{0000}-\x{0020}\x{007f}-\x{009f}\\\\]/u';

    /** Which scale a layer uses: text layers by role, plus button and badge; null for shapes and images. */
    public static function scaleKind(array $layer): ?string
    {
        return match ($layer['type']) {
            'text' => $layer['role'],
            'button', 'badge' => $layer['type'],
            default => null,
        };
    }

    /** Nearest current step for a stored size (current or legacy) of the given element kind. */
    public static function normalizeSize(string $kind, mixed $size): string
    {
        if (is_string($size) && in_array($size, self::SIZES, true)) {
            return $size;
        }
        $cqw = is_string($size) ? (self::LEGACY_CQW[$size] ?? null) : null;
        if ($cqw === null) {
            return self::DEFAULT_SIZE[$kind];
        }
        $best = self::SIZES[0];
        foreach (self::SIZES as $step) {
            if (abs(self::TYPE_SCALE[$kind][$step] - $cqw) < abs(self::TYPE_SCALE[$kind][$best] - $cqw)) {
                $best = $step;
            }
        }

        return $best;
    }

    /** Upgrades one decoded stored layer (stdClass) to the current `size` steps; other input passes through. */
    public static function upgradeLayer(mixed $candidate): mixed
    {
        if (! $candidate instanceof stdClass || ! property_exists($candidate, 'size')) {
            return $candidate;
        }
        $type = $candidate->type ?? null;
        $role = $candidate->role ?? null;
        $kind = $type === 'text' && is_string($role) && in_array($role, self::ROLES, true)
            ? $role
            : (($type === 'button' || $type === 'badge') ? $type : null);
        // Unknown strings stay as they are so strict validation rejects them.
        $known = is_string($candidate->size) && isset(self::LEGACY_CQW[$candidate->size]);
        if ($kind === null || ! $known) {
            return $candidate;
        }
        $upgraded = clone $candidate;
        $upgraded->size = self::normalizeSize($kind, $candidate->size);

        return $upgraded;
    }

    /**
     * The URL when it is a same-site path (`/x`), a fragment (`#x`) or an http(s)/mailto/tel URL; otherwise null
     * (blocks `javascript:`, `data:`, protocol-relative `//host`, whitespace, backslashes).
     */
    public static function safeHref(?string $value): ?string
    {
        $url = JsText::trim($value ?? '');
        if ($url === '' || JsText::utf16Length($url) > 500 || preg_match(self::UNSAFE_CHARS, $url) === 1) {
            return null;
        }
        if ($url[0] === '#') {
            return $url;
        }
        if ($url[0] === '/') {
            return str_starts_with($url, '//') ? null : $url;
        }

        return self::hasAllowedScheme($url) ? $url : null;
    }

    /** Image source: a same-site path or an http(s) URL. */
    public static function safeImageSrc(?string $value): ?string
    {
        $url = self::safeHref($value);
        if ($url === null || $url[0] === '#' || preg_match('/^(mailto|tel):/i', $url) === 1) {
            return null;
        }

        return $url;
    }

    /** `rel` for a link: external http(s) URLs never leak the opener or referrer. */
    public static function relFor(string $href): ?string
    {
        return preg_match('/^https?:/i', $href) === 1 ? 'noopener noreferrer' : null;
    }

    /** What `new URL(url)` accepts for the schemes safeHref allows. */
    private static function hasAllowedScheme(string $url): bool
    {
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):(.*)$/s', $url, $match) !== 1) {
            return false;
        }
        $scheme = strtolower($match[1]);
        if ($scheme === 'mailto' || $scheme === 'tel') {
            return true;
        }
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        $authority = preg_split('/[\/?#]/', ltrim($match[2], '/'), 2)[0];
        $at = strrpos($authority, '@');
        $hostPort = $at === false ? $authority : substr($authority, $at + 1);
        if (preg_match('/^(\[[0-9A-Fa-f:.]+\]|[^\x{0}-\x{20}#%\/:<>?@\[\\\\\]^|]+)(?::(\d*))?$/u', $hostPort, $parts) !== 1) {
            return false;
        }

        return ! isset($parts[2]) || $parts[2] === '' || (int) $parts[2] <= 65535;
    }

    /** JavaScript `Math.round(value * 100) / 100`; whole results are ints so documents stay byte-identical to JSON.stringify. */
    public static function round(int|float $value): int|float
    {
        $rounded = floor($value * 100 + 0.5) / 100;

        return $rounded == floor($rounded) && abs($rounded) < 1e15 ? (int) $rounded : $rounded;
    }

    /** Rounds geometry to two decimals so stored documents stay small and stable. */
    public static function rounded(array $layer): array
    {
        $next = $layer;
        foreach (['x', 'y', 'rotation', 'w', 'h'] as $key) {
            if (array_key_exists($key, $next) && (is_int($next[$key]) || is_float($next[$key]))) {
                $next[$key] = self::round($next[$key]);
            }
        }
        if (isset($layer['mobile'])) {
            $mobile = (array) $layer['mobile'];
            foreach (['x', 'y', 'w', 'h'] as $key) {
                if (isset($mobile[$key]) && (is_int($mobile[$key]) || is_float($mobile[$key]))) {
                    $mobile[$key] = self::round($mobile[$key]);
                }
            }
            $next['mobile'] = self::object($mobile);
        }

        return $next;
    }

    /** An empty map must encode as a JSON object. */
    public static function object(array $map): array|stdClass
    {
        return $map === [] ? new stdClass : $map;
    }

    /**
     * Stored document. `mobile` is only written when the slide has phone settings, so old documents stay identical.
     *
     * @param  list<array<string, mixed>>  $layers
     * @param  array{focus: array{x: int|float, y: int|float}}|null  $mobile
     */
    public static function serializeOverlay(array $layers, ?array $mobile = null): string
    {
        $doc = ['v' => self::OVERLAY_VERSION, 'layers' => array_map(self::rounded(...), array_values($layers))];
        if ($mobile) {
            $doc['mobile'] = ['focus' => ['x' => self::round($mobile['focus']['x']), 'y' => self::round($mobile['focus']['y'])]];
        }

        return json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }

    public static function newLayerId(): string
    {
        return 'l'.substr(str_pad(base_convert((string) random_int(0, 2821109907455), 10, 36), 8, '0', STR_PAD_LEFT), -8);
    }

    /** @return array<string, mixed> */
    public static function defaultLayer(string $type): array
    {
        $id = self::newLayerId();
        $common = ['rotation' => 0, 'opacity' => 100];

        return match ($type) {
            'text' => ['type' => $type, 'id' => $id, ...$common, 'x' => 6, 'y' => 36, 'w' => 55, 'role' => 'heading', 'text' => 'Your headline', 'size' => 'lg', 'align' => 'left', 'color' => 'white', 'font' => 'serif'],
            'button' => ['type' => $type, 'id' => $id, ...$common, 'x' => 8, 'y' => 72, 'label' => 'Learn more', 'href' => '/', 'variant' => 'solid', 'color' => 'gold', 'size' => 'md'],
            'badge' => ['type' => $type, 'id' => $id, ...$common, 'x' => 8, 'y' => 26, 'text' => 'New', 'color' => 'gold', 'size' => 'sm'],
            'shape' => ['type' => $type, 'id' => $id, ...$common, 'opacity' => 55, 'x' => 0, 'y' => 0, 'w' => 62, 'h' => 100, 'color' => 'navy-deep', 'radius' => 'none'],
            'image' => ['type' => $type, 'id' => $id, ...$common, 'x' => 8, 'y' => 8, 'w' => 10.1, 'h' => 18, 'src' => '/', 'alt' => '', 'radius' => 'none'],
        };
    }

    /**
     * Layers equivalent to the legacy title / subtitle / link fields of a Slide that has no overlay. The Slide read
     * model (`OverlaySchema::slideDocument`) applies it once, so everything downstream renders Layers only.
     *
     * @param  array{title?: ?string, subtitle?: ?string, linkUrl?: ?string}  $slide
     * @return list<array<string, mixed>>
     */
    public static function legacyLayers(array $slide): array
    {
        $layers = [];
        $title = isset($slide['title']) ? JsText::trim($slide['title']) : '';
        $subtitle = isset($slide['subtitle']) ? JsText::trim($slide['subtitle']) : '';
        $link = isset($slide['linkUrl']) ? JsText::trim($slide['linkUrl']) : '';
        if ($title !== '') {
            $layers[] = [...self::defaultLayer('text'), 'id' => 'legacy-title', 'x' => 6, 'y' => 30, 'w' => 60, 'text' => JsText::slice($title, 500)];
        }
        if ($subtitle !== '') {
            $layers[] = [
                ...self::defaultLayer('text'),
                'id' => 'legacy-subtitle',
                'x' => 6,
                'y' => $title !== '' ? 56 : 36,
                'w' => 55,
                'role' => 'subtitle',
                'text' => JsText::slice($subtitle, 500),
                'size' => 'md',
                'font' => 'sans',
            ];
        }
        if ($link !== '' && self::safeHref($link) !== null) {
            $layers[] = [...self::defaultLayer('button'), 'id' => 'legacy-link', 'x' => 6, 'y' => 74, 'label' => 'Explore more', 'href' => $link];
        }

        return $layers;
    }

    /**
     * Legacy columns kept in sync from the layers (admin labels, fallbacks).
     *
     * @param  list<array<string, mixed>>  $layers
     * @return array{title: ?string, subtitle: ?string, linkUrl: ?string}
     */
    public static function legacyFields(array $layers): array
    {
        $text = function (string $role) use ($layers): ?string {
            foreach ($layers as $layer) {
                if ($layer['type'] === 'text' && $layer['role'] === $role) {
                    return JsText::slice($layer['text'], 255);
                }
            }

            return null;
        };
        $button = null;
        foreach ($layers as $layer) {
            if ($layer['type'] === 'button') {
                $button = $layer;
                break;
            }
        }

        return [
            'title' => $text('heading'),
            'subtitle' => $text('subtitle'),
            'linkUrl' => $button ? JsText::slice($button['href'], 255) : null,
        ];
    }

    /**
     * DOM / reading order: top to bottom, then left to right. The array index stays the z-index.
     *
     * @param  list<array<string, mixed>>  $layers
     * @return list<array{layer: array<string, mixed>, z: int}>
     */
    public static function readingOrder(array $layers): array
    {
        $items = [];
        foreach (array_values($layers) as $index => $layer) {
            $items[] = ['layer' => $layer, 'z' => $index + 1];
        }
        usort($items, fn (array $a, array $b): int => ($a['layer']['y'] <=> $b['layer']['y']) ?: ($a['layer']['x'] <=> $b['layer']['x']) ?: ($a['z'] <=> $b['z']));

        return $items;
    }
}
