<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Validation of a content column that holds either a block document or legacy Markdown. Block documents
 * are validated and stored in normalised form; Markdown passes through unchanged.
 */
final class ContentField
{
    /**
     * Laravel validation rules. Add `nullable` yourself where the field may be null (the rule accepts
     * null for `$required = false`, and rejects it as "The content is required." otherwise).
     *
     * @return list<ContentRule>
     */
    public static function rules(bool $required = false): array
    {
        return [new ContentRule($required)];
    }

    /** The value to store for validated input: block documents in normalised form, Markdown unchanged. */
    public static function normalized(?string $value): string
    {
        $checked = Content::checkContent($value ?? '');

        return $checked['ok'] ? $checked['value'] : '';
    }
}
