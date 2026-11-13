<?php

namespace App\Admin;

use Closure;

/**
 * One field of an action's input: how it is read (`int`, `bool`, `string`, `text`, `raw`; see `ActionInput`), the
 * Laravel rules it must pass and the messages of those rules, declared together.
 */
final readonly class ActionField
{
    /**
     * @param  'int'|'bool'|'string'|'text'|'raw'  $type
     * @param  array<int|string, mixed>  $rules  rules of the field itself
     * @param  array<string, string>  $messages  rule => text; a key may also start with a nested path (`*.order.min`)
     * @param  array<string, array<int|string, mixed>>  $nested  rules of the field's items by path under the field (`*.id`)
     */
    private function __construct(
        public string $type,
        public array $rules,
        public array $messages,
        public array $nested = [],
    ) {}

    /** The `id` of the record an action works on. */
    public static function id(): self
    {
        return self::int(['required', 'integer']);
    }

    public static function int(array $rules = [], array $messages = []): self
    {
        return new self('int', $rules, $messages);
    }

    public static function bool(array $rules = [], array $messages = []): self
    {
        return new self('bool', $rules, $messages);
    }

    /** Trimmed text; an empty value is null. */
    public static function string(array $rules = [], array $messages = []): self
    {
        return new self('string', $rules, $messages);
    }

    /** Untrimmed text; an absent value is "". */
    public static function text(array $rules = [], array $messages = []): self
    {
        return new self('text', $rules, $messages);
    }

    /** Files, arrays and anything else, untouched. */
    public static function raw(array $rules = [], array $messages = []): self
    {
        return new self('raw', $rules, $messages);
    }

    /**
     * An array of at least one and at most `$max` items; `$nested` holds the rules of each item by path (`*.id`).
     *
     * @param  array<string, array<int|string, mixed>>  $nested
     * @param  array<string, string>  $messages  for the nested rules, by path (`*.order.min`)
     */
    public static function list(int $max, array $nested, array $messages = []): self
    {
        return new self('raw', ['required', 'array', 'min:1', "max:{$max}"], [
            'required' => 'Too small: expected array to have >=1 items',
            'min' => 'Too small: expected array to have >=1 items',
            'max' => "Too big: expected array to have <={$max} items",
        ] + $messages, $nested);
    }

    /** zod `z.number().int()` on JSON input: an integer, numeric strings are rejected. */
    public static function jsonInt(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value)) {
                $fail('Invalid input: expected number, received '.get_debug_type($value));
            }
        };
    }
}
