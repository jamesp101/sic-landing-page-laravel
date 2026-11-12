<?php

namespace Tests\Unit\Auth;

use App\Auth\Mail;
use Illuminate\Support\Facades\Mail as Mailer;
use Tests\TestCase;

class MailTest extends TestCase
{
    private const MESSAGE = ['to' => 'a@example.org', 'subject' => 'Hello', 'text' => 'Body'];

    public function test_it_sends_a_plain_text_message_with_reply_to(): void
    {
        Mail::sendMail(self::MESSAGE + ['replyTo' => 'Ana <ana@example.com>']);

        $sent = Mailer::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $email = $sent[0]->getOriginalMessage();
        $this->assertSame('Hello', $email->getSubject());
        $this->assertSame('a@example.org', $email->getTo()[0]->getAddress());
        $this->assertSame('ana@example.com', $email->getReplyTo()[0]->getAddress());
        $this->assertSame('Body', $email->getTextBody());
    }

    public function test_the_log_mailer_prints_the_message_outside_production(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.log.channel' => 'null']);
        Mailer::purge();

        Mail::sendMail(self::MESSAGE);
        $this->addToAssertionCount(1);
    }

    public function test_the_log_mailer_is_refused_in_production_by_name(): void
    {
        config(['mail.default' => 'log']);
        $this->app['env'] = 'production';

        $this->expectExceptionMessageMatches('/MAIL_MAILER=log is not allowed in production/');
        Mail::sendMail(self::MESSAGE);
    }
}
