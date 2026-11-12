<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use App\Content\Js;
use stdClass;

/**
 * The schema kit of the Block types: the subset of zod the TS modules use, with zod's messages. A field is
 * a spec array built by the factories below; {@see parse()} validates decoded JSON against it, appends
 * issues (`['path' => list<string|int>, 'message' => string]`) and returns the normalised value:
 * unknown object keys dropped, defaults filled, integral floats as ints.
 */
final class Z
{
    private static ?stdClass $missing = null;

    /** Marker for an absent object key (`undefined`). */
    public static function missing(): stdClass
    {
        return self::$missing ??= new stdClass;
    }

    /** @return array<string, mixed> */
    public static function string(?int $max = null, ?string $maxMessage = null, ?string $default = null): array
    {
        return ['t' => 'string', 'max' => $max, 'maxMessage' => $maxMessage, 'default' => $default];
    }

    /** `str(max)` of schema-kit: a string up to `max` characters, `''` when absent. */
    public static function str(int $max): array
    {
        return self::string($max, "Keep this under {$max} characters.", '');
    }

    /** @param list<string> $values */
    public static function enum(array $values, string $default, ?string $error = null): array
    {
        return ['t' => 'enum', 'values' => $values, 'default' => $default, 'error' => $error];
    }

    /** @param list<int> $values */
    public static function literals(array $values, int $default, ?string $error = null): array
    {
        return ['t' => 'literals', 'values' => $values, 'default' => $default, 'error' => $error];
    }

    public static function bool(bool $default): array
    {
        return ['t' => 'bool', 'default' => $default];
    }

    public static function int(?int $min, ?int $max, int|null|false $default = false, bool $nullable = false): array
    {
        return ['t' => 'int', 'min' => $min, 'max' => $max, 'default' => $default, 'nullable' => $nullable];
    }

    /** @param array<string, mixed> $item */
    public static function array(array $item, ?int $max = null, ?int $min = null, ?string $maxMessage = null): array
    {
        return ['t' => 'array', 'item' => $item, 'max' => $max, 'min' => $min, 'maxMessage' => $maxMessage];
    }

    /** @param array<string, array<string, mixed>> $shape */
    public static function object(array $shape): array
    {
        return ['t' => 'object', 'shape' => $shape];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<array{path: list<string|int>, message: string}>  $issues
     * @param  list<string|int>  $path
     */
    public static function parse(array $spec, mixed $value, array $path, array &$issues): mixed
    {
        $absent = $value === self::missing();
        $add = static function (string $message, ?array $at = null) use (&$issues, $path): void {
            $issues[] = ['path' => $at ?? $path, 'message' => $message];
        };
        $invalid = static fn (string $expected): string => 'Invalid input: expected '.$expected.', received '.($absent ? 'undefined' : Js::kind($value));

        switch ($spec['t']) {
            case 'string':
                if ($absent && $spec['default'] !== null) {
                    return $spec['default'];
                }
                if (! is_string($value)) {
                    $add($invalid('string'));

                    return null;
                }
                // zod counts code points (not UTF-16 units) for string length limits.
                if ($spec['max'] !== null && mb_strlen($value, 'UTF-8') > $spec['max']) {
                    $add($spec['maxMessage'] ?? "Too big: expected string to have <={$spec['max']} characters");
                }

                return $value;

            case 'enum':
                if ($absent) {
                    return $spec['default'];
                }
                if (! is_string($value) || ! in_array($value, $spec['values'], true)) {
                    $add($spec['error'] ?? 'Invalid option: expected one of '.implode('|', array_map(static fn (string $v): string => '"'.$v.'"', $spec['values'])));

                    return $spec['default'];
                }

                return $value;

            case 'literals':
                if ($absent) {
                    return $spec['default'];
                }
                if (Js::isNumber($value) && Js::isInteger($value) && in_array((int) $value, $spec['values'], true)) {
                    return (int) $value;
                }
                $add($spec['error'] ?? 'Invalid input');

                return $spec['default'];

            case 'bool':
                if ($absent) {
                    return $spec['default'];
                }
                if (! is_bool($value)) {
                    $add($invalid('boolean'));

                    return $spec['default'];
                }

                return $value;

            case 'int':
                if ($absent && $spec['default'] !== false) {
                    return $spec['default'];
                }
                if ($value === null && $spec['nullable']) {
                    return null;
                }
                if (! Js::isNumber($value)) {
                    $add($invalid('number'));

                    return null;
                }
                if (! Js::isInteger($value)) {
                    $add('Invalid input: expected int, received number');

                    return null;
                }
                if ($spec['min'] !== null && $value < $spec['min']) {
                    $add("Too small: expected number to be >={$spec['min']}");
                }
                if ($spec['max'] !== null && $value > $spec['max']) {
                    $add("Too big: expected number to be <={$spec['max']}");
                }

                return (int) $value;

            case 'array':
                if ($absent) {
                    return [];
                }
                if (! is_array($value)) {
                    $add($invalid('array'));

                    return [];
                }
                $out = [];
                foreach ($value as $index => $item) {
                    $out[] = self::parse($spec['item'], $item, [...$path, $index], $issues);
                }
                if ($spec['min'] !== null && count($value) < $spec['min']) {
                    $add("Too small: expected array to have >={$spec['min']} items");
                }
                if ($spec['max'] !== null && count($value) > $spec['max']) {
                    $add($spec['maxMessage'] ?? "Too big: expected array to have <={$spec['max']} items");
                }

                return $out;

            case 'object':
                if (! $value instanceof stdClass || $absent) {
                    $add($invalid('object'));

                    return [];
                }
                $out = [];
                foreach ($spec['shape'] as $key => $field) {
                    $out[$key] = self::parse($field, property_exists($value, (string) $key) ? $value->{$key} : self::missing(), [...$path, $key], $issues);
                }

                return $out;
        }

        throw new \LogicException('Unknown schema spec '.$spec['t']);
    }
}
