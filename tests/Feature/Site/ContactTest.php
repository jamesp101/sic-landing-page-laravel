<?php

namespace Tests\Feature\Site;

use App\Auth\RateLimit;
use App\Site\Contact;
use App\Support\RequestError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    private const FORM = ['name' => 'Ana', 'email' => 'ana@example.com', 'subject' => 'Hello', 'message' => 'A message of some length.'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_line_breaks_in_single_line_fields_are_flattened_so_they_cannot_inject_mail_headers(): void
    {
        $parsed = Contact::input([...self::FORM, 'name' => "Ana\r\nBcc: x@evil.test", 'subject' => "Hi\nthere"]);
        $this->assertSame('Ana Bcc: x@evil.test', $parsed['name']);
        $this->assertSame('Hi there', $parsed['subject']);
    }

    public function test_a_missing_name_and_a_too_short_message_are_rejected_with_the_form_messages(): void
    {
        try {
            Contact::input([...self::FORM, 'name' => ' ', 'message' => 'short', 'email' => 'nope']);
            $this->fail('expected validation to fail');
        } catch (ValidationException $e) {
            $this->assertSame('Please enter your name.', $e->errors()['name'][0]);
            $this->assertSame('Please write at least 10 characters.', $e->errors()['message'][0]);
            $this->assertSame('Enter a valid email address.', $e->errors()['email'][0]);
        }
    }

    public function test_length_limits(): void
    {
        $this->expectException(ValidationException::class);
        Contact::input([...self::FORM, 'subject' => str_repeat('x', 256)]);
    }

    public function test_the_mail_has_a_reply_to_that_cannot_break_out_of_the_display_name(): void
    {
        $mail = Contact::contactMail(Contact::input([...self::FORM, 'name' => 'A"<na>']), '1.2.3.4', 'office@sic.test');
        $this->assertSame('office@sic.test', $mail['to']);
        $this->assertSame('Ana <ana@example.com>', $mail['replyTo']);
        $this->assertSame('[Website contact] Hello', $mail['subject']);
    }

    public function test_nothing_is_sent_and_no_attempt_spent_when_the_honeypot_is_filled(): void
    {
        $input = Contact::input([...self::FORM, 'website' => 'http://spam.test']);
        for ($i = 0; $i < RateLimit::RATE_LIMITS['contact']['max'] + 2; $i++) {
            $this->assertNull(Contact::contactMail($input, '1.2.3.4', 'office@sic.test'));
        }
        $this->assertNotNull(Contact::contactMail(Contact::input(self::FORM), '1.2.3.4', 'office@sic.test'));
    }

    public function test_an_address_that_sends_too_many_messages_is_rejected_per_address(): void
    {
        $input = Contact::input(self::FORM);
        for ($i = 0; $i < RateLimit::RATE_LIMITS['contact']['max']; $i++) {
            Contact::contactMail($input, '1.2.3.4', 'office@sic.test');
        }
        try {
            Contact::contactMail($input, '1.2.3.4', 'office@sic.test');
            $this->fail('expected TOO_MANY_REQUESTS');
        } catch (RequestError $e) {
            $this->assertSame(RequestError::TOO_MANY_REQUESTS, $e->errorCode);
            $this->assertSame('Too many messages from your network. Please try again later.', $e->getMessage());
        }
        $this->assertNotNull(Contact::contactMail($input, '5.6.7.8', 'office@sic.test'));
    }

    public function test_the_action_delivers_through_the_endpoint_and_validates_input(): void
    {
        $this->postJson('/_actions/contact.send', self::FORM)->assertOk()->assertExactJson(['data' => ['sent' => true]]);
        $this->postJson('/_actions/contact.send', [...self::FORM, 'name' => ''])
            ->assertStatus(400)
            ->assertJsonPath('error.type', 'ActionInputError')
            ->assertJsonPath('error.fields.name.0', 'Please enter your name.');
    }

    public function test_the_no_js_form_post_redirects_on_success_and_rerenders_with_errors_otherwise(): void
    {
        $this->form(self::FORM)->assertRedirect('/contact?sent=1')->assertStatus(303);
        $this->form([...self::FORM, 'name' => '', 'message' => 'keep me?'])
            ->assertOk()
            ->assertSee('Please fix the highlighted fields and try again.')
            ->assertSee('Please enter your name.')
            ->assertSee('keep me?');
    }

    /** A browser form POST: anonymous bodies must declare their Content-Length (RequestPolicy). */
    private function form(array $data): TestResponse
    {
        return $this->call('POST', '/contact', $data, [], [], ['CONTENT_LENGTH' => (string) strlen(http_build_query($data))]);
    }
}
