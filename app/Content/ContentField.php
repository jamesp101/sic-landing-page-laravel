<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Validation of a content column that holds either a block document or legacy Markdown. Block documents
 * are validated here and stored in normalised form by `Content::forStorage`; Markdown passes through unchanged.
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
}
