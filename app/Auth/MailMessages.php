<?php

namespace App\Auth;

/**
 * The text of every mail the site sends. Callers pass data only; delivery is Mail::sendMail.
 * A message is `['to' => string, 'subject' => string, 'text' => string, 'replyTo'? => string]`.
 */
final class MailMessages
{
    /** The link mail for a User who asked to reset their password. */
    public static function resetPasswordMessage(string $to, string $url): array
    {
        return [
            'to' => $to,
            'subject' => 'Reset your San Isidro College password',
            'text' => "Use this link to reset your password:\n\n{$url}\n\nIf you did not request a reset, ignore this email.",
        ];
    }

    /** The link mail that verifies a User's email address. */
    public static function verifyEmailMessage(string $to, string $url): array
    {
        return [
            'to' => $to,
            'subject' => 'Verify your email address',
            'text' => "Verify your email:\n\n{$url}",
        ];
    }

    /** The warning sent to the User's previous address after their email address changed to `$newEmail`. */
    public static function emailChangedMessage(string $previousEmail, string $newEmail): array
    {
        return [
            'to' => $previousEmail,
            'subject' => 'Your San Isidro College email address was changed',
            'text' => "The email address of your San Isidro College account was changed to {$newEmail}.\n\nIf you did not make this change, contact the site administrator immediately.",
        ];
    }

    /**
     * A contact-form submission, forwarded to the college. `name` and `subject` are already single-line.
     *
     * @param  array{name: string, email: string, subject: string, message: string}  $form
     */
    public static function contactMessage(string $to, array $form): array
    {
        return [
            'to' => $to,
            // Quotes and angle brackets cannot break out of the display name.
            'replyTo' => preg_replace('/[<>"]/', '', $form['name'])." <{$form['email']}>",
            'subject' => "[Website contact] {$form['subject']}",
            'text' => "From: {$form['name']} <{$form['email']}>\nSubject: {$form['subject']}\n\n{$form['message']}\n",
        ];
    }
}
