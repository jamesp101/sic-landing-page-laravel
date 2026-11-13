<?php

namespace App\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Input of an action: JSON bodies arrive typed, multipart forms as strings (booleans as "true"/"false", numbers
 * as digits, empty fields as ""). Each `ActionField` says how its field is read, so both shapes validate the same way:
 * `int`, `bool`, `string` (trimmed; empty = null), `text` (untrimmed; absent = "") or `raw` (files and anything else, untouched).
 * Failures are ValidationExceptions (rendered as the action input error with per-field messages).
 */
final class ActionInput
{
    /** Messages for rules that have no message of their own in the action (zod's defaults). */
    private const DEFAULT_MESSAGES = [
        'integer' => 'Invalid input: expected int, received number',
        'boolean' => 'Invalid input: expected boolean, received string',
        'string' => 'Invalid input: expected string, received number',
        'max' => 'Too big: expected string to have <=:max characters',
    ];

    /** zod's message for a missing value, by how the field is read. */
    private const MISSING = [
        'int' => 'Invalid input: expected number, received undefined',
        'bool' => 'Invalid input: expected boolean, received undefined',
        'string' => 'Invalid input: expected string, received undefined',
        'text' => 'Invalid input: expected string, received undefined',
        'raw' => 'Invalid input: expected string, received undefined',
    ];

    /**
     * @param  array<string, ActionField>  $fields
     * @return array<string, mixed> the validated (typed) fields
     */
    public static function validate(Request $request, array $fields): array
    {
        $input = $request->all();
        $data = [];
        $rules = [];
        $messages = [];
        $isForm = ! $request->isJson();
        foreach ($fields as $name => $field) {
            if ($isForm && $field->type === 'bool') {
                // What Astro's form actions did for `z.boolean()`: "true" / "false" as such, any other
                // submitted value is true, an absent field (an unchecked box) is false. JSON stays strict.
                $value = $input[$name] ?? null;
                $data[$name] = match (true) {
                    $value === 'true' => true,
                    $value === 'false' => false,
                    default => array_key_exists($name, $input),
                };
            } else {
                $data[$name] = self::coerce($input[$name] ?? null, $field->type);
            }
            $rules[$name] = $field->rules;
            foreach ($field->nested as $path => $nestedRules) {
                $rules["{$name}.{$path}"] = $nestedRules;
            }
            foreach ($field->messages as $key => $text) {
                $messages["{$name}.{$key}"] = $text;
            }
            $messages += ["{$name}.required" => self::MISSING[$field->type]];
        }

        return Validator::make($data, $rules, $messages + self::DEFAULT_MESSAGES)->validate();
    }

    private static function coerce(mixed $value, string $type): mixed
    {
        if ($type === 'raw') {
            return $value;
        }
        if ($type === 'text') {
            return $value ?? '';
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
        }
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => is_string($value) && preg_match('/^-?\d+$/', $value) ? (int) $value : (is_float($value) && floor($value) === $value ? (int) $value : $value),
            'bool' => $value,
            default => $value,
        };
    }
}
