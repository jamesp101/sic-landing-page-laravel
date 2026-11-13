<?php

namespace Tests\Unit\Admin\Core;

use App\Admin\ActionField;
use App\Admin\ActionInput;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ActionInputTest extends TestCase
{
    private function jsonRequest(array $body): Request
    {
        return Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    }

    private function formRequest(array $fields): Request
    {
        return Request::create('/', 'POST', $fields);
    }

    /** @return array<string, array<int, string>> */
    private function errors(Request $request, array $fields): array
    {
        try {
            ActionInput::validate($request, $fields);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('Expected the input to be rejected.');
    }

    public function test_form_strings_are_read_as_typed_values(): void
    {
        $input = ActionInput::validate($this->formRequest(['id' => '7', 'name' => '  Ana ', 'note' => '', 'isActive' => 'false']), [
            'id' => ActionField::id(),
            'name' => ActionField::string(['required', 'string']),
            'note' => ActionField::string(['nullable', 'string']),
            'isActive' => ActionField::bool(['required', 'boolean']),
        ]);

        $this->assertSame(['id' => 7, 'name' => 'Ana', 'note' => null, 'isActive' => false], $input);
    }

    public function test_missing_required_field_has_the_message_of_its_declaration_or_the_read_type(): void
    {
        $errors = $this->errors($this->jsonRequest([]), [
            'name' => ActionField::string(['required', 'string'], ['required' => 'The name is required.']),
            'id' => ActionField::id(),
        ]);

        $this->assertSame(['The name is required.'], $errors['name']);
        $this->assertSame(['Invalid input: expected number, received undefined'], $errors['id']);
    }

    public function test_json_integer_rejects_numeric_strings_at_any_depth(): void
    {
        $fields = ['items' => ActionField::list(5, ['*.id' => ['required', ActionField::jsonInt()]])];

        $this->assertSame(
            ['items.0.id' => ['Invalid input: expected number, received string']],
            $this->errors($this->jsonRequest(['items' => [['id' => '3']]]), $fields),
        );
        $this->assertSame(['items' => [['id' => 3]]], ActionInput::validate($this->jsonRequest(['items' => [['id' => 3]]]), $fields));
    }

    public function test_list_bounds_use_the_array_messages_and_nested_messages_are_relative_to_the_field(): void
    {
        $fields = ['items' => ActionField::list(1, ['*.order' => ['required', ActionField::jsonInt(), 'numeric', 'min:0']], ['*.order.min' => 'Too small.'])];

        $this->assertSame(['Too small: expected array to have >=1 items'], $this->errors($this->jsonRequest(['items' => []]), $fields)['items']);
        $this->assertSame(['Too big: expected array to have <=1 items'], $this->errors($this->jsonRequest(['items' => [['order' => 1], ['order' => 2]]]), $fields)['items']);
        $this->assertSame(['Too small.'], $this->errors($this->jsonRequest(['items' => [['order' => -1]]]), $fields)['items.0.order']);
    }

    public function test_default_max_message_names_the_limit(): void
    {
        $errors = $this->errors($this->jsonRequest(['name' => str_repeat('a', 4)]), ['name' => ActionField::string(['required', 'string', 'max:3'])]);

        $this->assertSame(['Too big: expected string to have <=3 characters'], $errors['name']);
    }
}
