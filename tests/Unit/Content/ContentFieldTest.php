<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Content\ContentField;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** `ContentField::rules()` equals zod's `contentField()`: same acceptance, same messages. */
final class ContentFieldTest extends TestCase
{
    private const DOCUMENT = '{"type":"sic-blocks","version":1,"blocks":[{"id":"a1","type":"paragraph","props":{"text":"Hi"}}]}';

    private function errors(mixed $value, bool $required, bool $present = true): array
    {
        $data = $present ? ['content' => $value] : [];

        return Validator::make($data, ['content' => ContentField::rules($required)])->errors()->get('content');
    }

    public function test_markdown_and_valid_documents_pass(): void
    {
        $this->assertSame([], $this->errors('# Title', true));
        $this->assertSame([], $this->errors(self::DOCUMENT, true));
        $this->assertSame([], $this->errors('', false));
        $this->assertSame([], $this->errors(null, false));
    }

    public function test_an_invalid_block_document_is_rejected_with_the_editor_message(): void
    {
        $errors = $this->errors('{"type":"sic-blocks","version":1,"blocks":[{"id":"a","type":"button","props":{"label":"Go","href":"javascript:alert(1)"}}]}', false);

        $this->assertSame(['Button 1: Enter a valid link (https://, /path, mailto: or tel:).'], $errors);
        $this->assertSame(['This content is not valid JSON.'], $this->errors('{"type":"sic-blocks", oops', false));
    }

    public function test_required_rejects_content_with_nothing_in_it(): void
    {
        $this->assertSame(['The content is required.'], $this->errors('', true));
        $this->assertSame(['The content is required.'], $this->errors(null, true));
        $this->assertSame(['The content is required.'], $this->errors('{"type":"sic-blocks","version":1,"blocks":[]}', true));
        $this->assertSame([], $this->errors('   x', true));
    }

    public function test_non_strings_are_rejected(): void
    {
        $this->assertSame(['Invalid input: expected string, received number'], $this->errors(5, false));
        $this->assertSame(['Invalid input: expected string, received array'], $this->errors(['x'], false));
    }

    public function test_normalized_returns_the_value_to_store(): void
    {
        $this->assertSame('{"type":"sic-blocks","version":1,"blocks":[{"id":"a1","type":"paragraph","props":{"text":"Hi","size":"base","tone":"default","align":"inherit"}}]}', ContentField::normalized(self::DOCUMENT));
        $this->assertSame('# Title', ContentField::normalized('# Title'));
        $this->assertSame('', ContentField::normalized(null));
        $this->assertSame('', ContentField::normalized('{"type":"sic-blocks","version":1,"blocks":[]}'));
    }
}
