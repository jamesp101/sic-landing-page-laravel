<?php

namespace App\Auth;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail as Mailer;
use RuntimeException;
use Symfony\Component\Mime\Address;

final class Mail
{
    /**
     * Sends a plain-text message (see MailMessages) on Laravel Mail. MAIL_MAILER=log (dev) writes it to the log;
     * that mailer prints reset links and contact messages, so it is refused in production (the send throws).
     *
     * @param  array{to: string, subject: string, text: string, replyTo?: string}  $message
     */
    public static function sendMail(array $message): void
    {
        if (config('mail.default') === 'log' && app()->isProduction()) {
            throw new RuntimeException('MAIL_MAILER=log is not allowed in production: set MAIL_MAILER=smtp and the MAIL_* variables.');
        }
        Mailer::raw($message['text'], function (Message $mail) use ($message): void {
            $mail->to($message['to'])->subject($message['subject']);
            if (isset($message['replyTo'])) {
                $replyTo = Address::create($message['replyTo']);
                $mail->replyTo($replyTo->getAddress(), $replyTo->getName());
            }
        });
    }
}
