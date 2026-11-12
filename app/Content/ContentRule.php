<?php

declare(strict_types=1);

namespace App\Content;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation of a content column ({@see ContentField}): a block document must validate, Markdown passes.
 * Implicit, because an emptied field arrives as `null` (ConvertEmptyStringsToNull) and `contentField`
 * treats it as the empty string that a required field rejects.
 */
final class ContentRule implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(private readonly bool $required) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null && ! $this->required) {
            return;
        }
        if ($value !== null && ! is_string($value)) {
            $fail('Invalid input: expected string, received '.Js::kind($value));

            return;
        }
        $checked = Content::checkContent($value ?? '');
        if (! $checked['ok']) {
            $fail($checked['message']);
        } elseif ($this->required && Js::blank($checked['value'])) {
            $fail('The content is required.');
        }
    }
}
