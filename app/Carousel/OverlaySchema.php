<?php

namespace App\Carousel;

use JsonException;
use stdClass;

/**
 * Validation of the slide overlay document (writes, admin and tests; see Overlay for the format). A port of the zod
 * schema: the first problem found is reported with the same message, in the same order (fields in schema order,
 * unrecognised keys last).
 *
 * Input is `json_decode`d without assoc (objects are stdClass, so `{}` and `[]` stay different); results are
 * associative arrays in schema key order, so `Overlay::serializeOverlay` writes the same bytes as the TS code.
 */
final class OverlaySchema
{
    /** Numeric bounds of a Layer's geometry and look: the schema validates against them and the designer clamps edits to them. */
    public const LAYER_LIMITS = [
        'x' => [0, 100],
        'y' => [0, 100],
        'w' => [1, 100],
        'h' => [1, 100],
        'rotation' => [-180, 180],
        'opacity' => [10, 100],
    ];

    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,40}\z/';

    private const DISCRIMINATOR = "Invalid discriminator value. Expected 'text' | 'button' | 'badge' | 'shape' | 'image'";

    private const HREF_MESSAGE = 'Enter a path such as /posts, an https:// link, mailto: or tel:.';

    /** Marks an absent property. */
    private static ?object $undefined = null;

    /**
     * Strict validation for writes: any unknown property, bad URL or oversize document is an error.
     *
     * @return array{ok: true, layers: list<array<string, mixed>>, mobile: ?array}|array{ok: false, error: string}
     */
    public static function parseOverlayStrict(string $raw): array
    {
        if (JsText::utf16Length($raw) > Overlay::MAX_OVERLAY_JSON) {
            return ['ok' => false, 'error' => 'The slide design is too large.'];
        }
        try {
            $data = self::decode($raw);
        } catch (JsonException) {
            return ['ok' => false, 'error' => 'The slide design is not valid JSON.'];
        }
        try {
            $doc = self::document(self::upgradeDocument($data));
        } catch (OverlayIssue $issue) {
            $suffix = $issue->layer === null ? '' : ' (layer '.($issue->layer + 1).')';

            return ['ok' => false, 'error' => $issue->getMessage().$suffix];
        }

        return ['ok' => true, 'layers' => array_map(Overlay::rounded(...), $doc['layers']), 'mobile' => $doc['mobile']];
    }

    /**
     * Tolerant read for rendering: null means "no overlay" (see `slideDocument` for legacy Slides).
     * Invalid layers are dropped (a layer with only invalid phone overrides keeps its desktop design); a document whose
     * layers are all invalid counts as no overlay; invalid slide-level phone settings are ignored.
     *
     * @return array{layers: list<array<string, mixed>>, mobile: ?array}|null
     */
    public static function parseOverlayDocument(?string $raw): ?array
    {
        if ($raw === null || $raw === '' || JsText::utf16Length($raw) > Overlay::MAX_OVERLAY_JSON) {
            return null;
        }
        try {
            $doc = self::decode($raw);
        } catch (JsonException) {
            return null;
        }
        if (! $doc instanceof stdClass) {
            return null;
        }
        $version = $doc->v ?? null;
        $candidates = $doc->layers ?? null;
        if (! (is_int($version) && $version === Overlay::OVERLAY_VERSION) || ! is_array($candidates)) {
            return null;
        }

        $seen = [];
        $layers = [];
        foreach (array_slice($candidates, 0, Overlay::MAX_LAYERS) as $candidate) {
            $upgraded = Overlay::upgradeLayer($candidate);
            try {
                $layer = self::layer($upgraded);
            } catch (OverlayIssue) {
                if (! $upgraded instanceof stdClass || ! property_exists($upgraded, 'mobile')) {
                    continue;
                }
                $desktop = clone $upgraded;
                unset($desktop->mobile);
                try {
                    $layer = self::layer($desktop);
                } catch (OverlayIssue) {
                    continue;
                }
            }
            if (isset($seen[$layer['id']])) {
                continue;
            }
            $seen[$layer['id']] = true;
            $layers[] = $layer;
        }
        if ($layers === [] && $candidates !== []) {
            return null;
        }
        try {
            $mobile = self::slideMobile($doc->mobile ?? self::undefined());
        } catch (OverlayIssue) {
            $mobile = null;
        }

        return ['layers' => $layers, 'mobile' => $mobile];
    }

    /**
     * The layers of a stored overlay (see parseOverlayDocument); null = no overlay.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function parseOverlay(?string $raw): ?array
    {
        return self::parseOverlayDocument($raw)['layers'] ?? null;
    }

    /**
     * The Slide read model: the Layers (and phone settings) of a stored Slide. A legacy Slide (no usable overlay, only
     * title / subtitle / link) is converted to Layers here, once, so the public Carousel and the designer only ever see Layers.
     *
     * @param  array{overlay: ?string, title: ?string, subtitle: ?string, linkUrl: ?string}  $row
     * @return array{layers: list<array<string, mixed>>, mobile: ?array}
     */
    public static function slideDocument(array $row): array
    {
        return self::parseOverlayDocument($row['overlay']) ?? ['layers' => Overlay::legacyLayers($row), 'mobile' => null];
    }

    // ---------------------------------------------------------------------------------------------------------

    /** `json_decode` to stdClass/arrays; whole floats become ints (JavaScript has one number type). */
    private static function decode(string $raw): mixed
    {
        return self::wholeNumbers(json_decode($raw, false, 512, JSON_THROW_ON_ERROR));
    }

    private static function wholeNumbers(mixed $value): mixed
    {
        if (is_float($value)) {
            return $value == floor($value) && abs($value) < 9007199254740992 ? (int) $value : $value;
        }
        if (is_array($value)) {
            return array_map(self::wholeNumbers(...), $value);
        }
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = self::wholeNumbers($item);
            }
        }

        return $value;
    }

    /** @return array{layers: list<array<string, mixed>>, mobile: ?array} */
    private static function document(mixed $data): array
    {
        $doc = self::object($data);
        $version = self::prop($doc, 'v');
        if (! is_int($version) || $version !== Overlay::OVERLAY_VERSION) {
            throw new OverlayIssue('Invalid input: expected '.Overlay::OVERLAY_VERSION);
        }
        $candidates = self::prop($doc, 'layers');
        if (! is_array($candidates)) {
            throw new OverlayIssue('Invalid input: expected array, received '.self::kind($candidates));
        }
        $layers = [];
        foreach (array_values($candidates) as $index => $candidate) {
            try {
                $layers[] = self::layer($candidate);
            } catch (OverlayIssue $issue) {
                throw $issue->inLayer($index);
            }
        }
        if (count($layers) > Overlay::MAX_LAYERS) {
            throw new OverlayIssue('A slide can have at most '.Overlay::MAX_LAYERS.' layers.');
        }
        if (count(array_unique(array_column($layers, 'id'))) !== count($layers)) {
            throw new OverlayIssue('Layer ids must be unique.');
        }
        $mobile = self::slideMobile(self::prop($doc, 'mobile'));
        self::strictKeys($doc, ['v', 'layers', 'mobile']);

        return ['layers' => $layers, 'mobile' => $mobile];
    }

    private static function upgradeDocument(mixed $data): mixed
    {
        if (! $data instanceof stdClass || ! is_array($data->layers ?? null)) {
            return $data;
        }
        $upgraded = clone $data;
        $upgraded->layers = array_map(Overlay::upgradeLayer(...), $data->layers);

        return $upgraded;
    }

    private static function undefined(): object
    {
        return self::$undefined ??= new stdClass;
    }

    private static function prop(stdClass $object, string $key): mixed
    {
        return property_exists($object, $key) ? $object->{$key} : self::undefined();
    }

    private static function kind(mixed $value): string
    {
        return match (true) {
            $value === self::undefined() => 'undefined',
            $value === null => 'null',
            is_array($value) => 'array',
            $value instanceof stdClass => 'object',
            is_string($value) => 'string',
            is_bool($value) => 'boolean',
            default => 'number',
        };
    }

    private static function object(mixed $value): stdClass
    {
        if (! $value instanceof stdClass || $value === self::undefined()) {
            throw new OverlayIssue('Invalid input: expected object, received '.self::kind($value));
        }

        return $value;
    }

    /** Unrecognised keys come after every problem of the known fields. */
    private static function strictKeys(stdClass $object, array $known): void
    {
        $unknown = array_values(array_diff(array_keys(get_object_vars($object)), $known));
        if ($unknown === []) {
            return;
        }
        $quoted = implode(', ', array_map(fn (string $key): string => '"'.$key.'"', $unknown));
        throw new OverlayIssue(count($unknown) === 1 ? "Unrecognized key: {$quoted}" : "Unrecognized keys: {$quoted}");
    }

    private static function number(mixed $value, array $range, bool $int = false): int|float
    {
        if (! (is_int($value) || is_float($value))) {
            throw new OverlayIssue('Invalid input: expected number, received '.self::kind($value));
        }
        [$min, $max] = $range;
        if ($value < $min) {
            throw new OverlayIssue("Too small: expected number to be >={$min}");
        }
        if ($value > $max) {
            throw new OverlayIssue("Too big: expected number to be <={$max}");
        }
        if ($int && ! is_int($value)) {
            throw new OverlayIssue('Invalid input: expected int, received number');
        }

        return $value;
    }

    /** @param list<string> $options */
    private static function enum(mixed $value, array $options): string
    {
        if (! is_string($value) || ! in_array($value, $options, true)) {
            throw new OverlayIssue('Invalid option: expected one of '.implode('|', array_map(fn (string $o): string => '"'.$o.'"', $options)));
        }

        return $value;
    }

    private static function string(mixed $value): string
    {
        if (! is_string($value)) {
            throw new OverlayIssue('Invalid input: expected string, received '.self::kind($value));
        }

        return $value;
    }

    /** A trimmed string of 1..$max characters. */
    private static function text(mixed $value, string $empty, int $max, string $tooLong): string
    {
        $text = JsText::trim(self::string($value));
        if (JsText::length($text) < 1) {
            throw new OverlayIssue($empty);
        }
        if (JsText::length($text) > $max) {
            throw new OverlayIssue($tooLong);
        }

        return $text;
    }

    /** @return array{x: int|float, y: int|float}|null */
    private static function slideMobile(mixed $value): ?array
    {
        if ($value === self::undefined()) {
            return null;
        }
        $object = self::object($value);
        $focus = self::object(self::prop($object, 'focus'));
        $x = self::number(self::prop($focus, 'x'), self::LAYER_LIMITS['x']);
        $y = self::number(self::prop($focus, 'y'), self::LAYER_LIMITS['y']);
        self::strictKeys($focus, ['x', 'y']);
        self::strictKeys($object, ['focus']);

        return ['focus' => ['x' => $x, 'y' => $y]];
    }

    /** Phone overrides: percent bounds, token sizes only; a position needs both x and y. */
    private static function mobileOverride(mixed $value): array|stdClass
    {
        $object = self::object($value);
        $out = [];
        foreach (['x' => 'x', 'y' => 'y', 'w' => 'w', 'h' => 'h'] as $key => $limit) {
            $item = self::prop($object, $key);
            if ($item !== self::undefined()) {
                $out[$key] = self::number($item, self::LAYER_LIMITS[$limit]);
            }
        }
        // zod order: x, y, w, h, size, hidden
        $size = self::prop($object, 'size');
        if ($size !== self::undefined()) {
            $out['size'] = self::enum($size, Overlay::SIZES);
        }
        $hidden = self::prop($object, 'hidden');
        if ($hidden !== self::undefined()) {
            if (! is_bool($hidden)) {
                throw new OverlayIssue('Invalid input: expected boolean, received '.self::kind($hidden));
            }
            $out['hidden'] = $hidden;
        }
        self::strictKeys($object, ['x', 'y', 'w', 'h', 'size', 'hidden']);
        if (isset($out['x']) !== isset($out['y'])) {
            throw new OverlayIssue('Set both the phone X and Y position.');
        }

        return Overlay::object($out);
    }

    /** Validates one layer (decoded stdClass) and returns it with defaults filled, in schema key order. */
    private static function layer(mixed $value): array
    {
        if (! $value instanceof stdClass) {
            throw new OverlayIssue('Invalid input: expected object, received '.self::kind($value));
        }
        $type = self::prop($value, 'type');
        if (! is_string($type) || ! in_array($type, Overlay::LAYER_TYPES, true)) {
            throw new OverlayIssue(self::DISCRIMINATOR);
        }

        $id = self::string(self::prop($value, 'id'));
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new OverlayIssue('Invalid layer id.');
        }
        $out = ['type' => $type, 'id' => $id];
        $out['x'] = self::number(self::prop($value, 'x'), self::LAYER_LIMITS['x']);
        $out['y'] = self::number(self::prop($value, 'y'), self::LAYER_LIMITS['y']);
        $rotation = self::prop($value, 'rotation');
        $out['rotation'] = $rotation === self::undefined() ? 0 : self::number($rotation, self::LAYER_LIMITS['rotation']);
        $opacity = self::prop($value, 'opacity');
        $out['opacity'] = $opacity === self::undefined() ? 100 : self::number($opacity, self::LAYER_LIMITS['opacity'], true);
        $mobile = self::prop($value, 'mobile');
        if ($mobile !== self::undefined()) {
            $out['mobile'] = self::mobileOverride($mobile);
        }

        $span = fn (string $key): int|float => self::number(self::prop($value, $key), self::LAYER_LIMITS[$key]);
        $color = fn (): string => self::enum(self::prop($value, 'color'), Overlay::COLORS);
        $size = fn (): string => self::enum(self::prop($value, 'size'), Overlay::SIZES);
        $radius = fn (): string => self::enum(self::prop($value, 'radius'), Overlay::RADII);

        $fields = match ($type) {
            'text' => function () use ($value, $span, $color, $size): array {
                $f = ['w' => $span('w')];
                $f['role'] = self::enum(self::prop($value, 'role'), Overlay::ROLES);
                $f['text'] = self::text(self::prop($value, 'text'), 'Text layers cannot be empty.', 500, 'Text is limited to 500 characters.');
                $f['size'] = $size();
                $f['align'] = self::enum(self::prop($value, 'align'), Overlay::ALIGNS);
                $f['color'] = $color();
                $f['font'] = self::enum(self::prop($value, 'font'), Overlay::FONTS);

                return $f;
            },
            'button' => function () use ($value, $color, $size): array {
                $f = ['label' => self::text(self::prop($value, 'label'), 'Button labels cannot be empty.', 80, 'Button labels are limited to 80 characters.')];
                $href = JsText::trim(self::string(self::prop($value, 'href')));
                if (Overlay::safeHref($href) === null) {
                    throw new OverlayIssue(self::HREF_MESSAGE);
                }
                $f['href'] = $href;
                $f['variant'] = self::enum(self::prop($value, 'variant'), Overlay::BUTTON_VARIANTS);
                $f['color'] = $color();
                $f['size'] = $size();

                return $f;
            },
            'badge' => function () use ($value, $color, $size): array {
                $f = ['text' => self::text(self::prop($value, 'text'), 'Badges cannot be empty.', 80, 'Badges are limited to 80 characters.')];
                $f['color'] = $color();
                $f['size'] = $size();

                return $f;
            },
            'shape' => function () use ($span, $color, $radius): array {
                return ['w' => $span('w'), 'h' => $span('h'), 'color' => $color(), 'radius' => $radius()];
            },
            'image' => function () use ($value, $span, $radius): array {
                $f = ['w' => $span('w'), 'h' => $span('h')];
                $src = JsText::trim(self::string(self::prop($value, 'src')));
                if (Overlay::safeImageSrc($src) === null) {
                    throw new OverlayIssue('The image URL is not allowed.');
                }
                $f['src'] = $src;
                $f['alt'] = self::text(self::prop($value, 'alt'), 'Describe the image (alt text is required).', 200, 'Alt text is limited to 200 characters.');
                $f['radius'] = $radius();

                return $f;
            },
        };

        $fields = $fields();
        self::strictKeys($value, ['type', 'id', 'x', 'y', 'rotation', 'opacity', 'mobile', ...array_keys($fields)]);

        return [...$out, ...$fields];
    }
}
