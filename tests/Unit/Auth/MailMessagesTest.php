<?php

namespace Tests\Unit\Auth;

use App\Auth\MailMessages;
use PHPUnit\Framework\TestCase;

class MailMessagesTest extends TestCase
{
    public function test_it_puts_the_link_in_the_reset_and_verification_mails(): void
    {
        $reset = MailMessages::resetPasswordMessage('ana@example.com', 'https://sic.test/reset?token=t');
        $this->assertSame('ana@example.com', $reset['to']);
        $this->assertSame('Reset your San Isidro College password', $reset['subject']);
        $this->assertStringContainsString('https://sic.test/reset?token=t', $reset['text']);

        $verify = MailMessages::verifyEmailMessage('ana@example.com', 'https://sic.test/verify');
        $this->assertSame('ana@example.com', $verify['to']);
        $this->assertSame('Verify your email address', $verify['subject']);
        $this->assertStringContainsString('https://sic.test/verify', $verify['text']);
    }

    public function test_it_sends_the_email_change_warning_to_the_previous_address_and_names_the_new_one(): void
    {
        $mail = MailMessages::emailChangedMessage('old@example.com', 'new@example.com');
        $this->assertSame('old@example.com', $mail['to']);
        $this->assertStringContainsString('new@example.com', $mail['text']);
    }

    public function test_it_builds_the_contact_mail_with_a_reply_to_that_cannot_break_out_of_the_display_name(): void
    {
        $mail = MailMessages::contactMessage('office@sic.test', [
            'name' => 'A"<na>',
            'email' => 'ana@example.com',
            'subject' => 'Hello',
            'message' => 'A message.',
        ]);
        $this->assertSame('office@sic.test', $mail['to']);
        $this->assertSame('Ana <ana@example.com>', $mail['replyTo']);
        $this->assertSame('[Website contact] Hello', $mail['subject']);
        $this->assertSame("From: A\"<na> <ana@example.com>\nSubject: Hello\n\nA message.\n", $mail['text']);
    }
}
