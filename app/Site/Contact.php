<?php

namespace App\Site;

use App\Auth\MailMessages;
use App\Auth\RateLimit;
use App\Support\RequestError;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The public contact form: input rules (single-line fields), honeypot, per-IP rate limit and the mail to send.
 * `ContactActions::send` only delivers the result.
 */
final class Contact
{
    /** Validation messages, verbatim from the form. */
    public const MESSAGES = [
        'name.required' => 'Please enter your name.',
        'name.max' => 'Please keep this under 255 characters.',
        'email.required' => 'Please enter your email address.',
        'email.max' => 'Too big: expected string to have <=255 characters',
        'email.email' => 'Enter a valid email address.',
        'subject.required' => 'Please enter a subject.',
        'subject.max' => 'Please keep this under 255 characters.',
        'message.required' => 'Please write at least 10 characters.',
        'message.min' => 'Please write at least 10 characters.',
        'message.max' => 'Please keep your message under 5000 characters.',
    ];

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:255', 'email'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            // Honeypot: real visitors never see or fill this.
            'website' => ['nullable', 'string'],
        ];
    }

    /**
     * Validated input: fields trimmed, line breaks in the single-line fields flattened so they cannot inject mail headers.
     *
     * @param  array<string, mixed>  $raw
     * @return array{name: string, email: string, subject: string, message: string, website?: ?string}
     *
     * @throws ValidationException
     */
    public static function input(array $raw): array
    {
        $data = [];
        foreach (['name', 'email', 'subject', 'message', 'website'] as $field) {
            $value = $raw[$field] ?? null;
            $data[$field] = is_string($value) ? self::trim($value) : $value;
        }
        $validated = Validator::make($data, self::rules(), self::MESSAGES)->validate();
        foreach (['name', 'subject'] as $field) {
            $validated[$field] = preg_replace('/[\r\n]+/', ' ', $validated[$field]);
        }

        return $validated;
    }

    /**
     * The mail to send for a contact submission, or null when nothing should be sent (a bot filled the honeypot and
     * gets a normal-looking success). Throws TOO_MANY_REQUESTS past the per-address limit.
     *
     * @param  array{name: string, email: string, subject: string, message: string, website?: ?string}  $input
     * @return array{to: string, subject: string, text: string, replyTo: string}|null
     */
    public static function contactMail(array $input, string $clientAddress, string $to): ?array
    {
        if (isset($input['website']) && $input['website'] !== '') {
            return null;
        }
        if (! RateLimit::consumeRateLimit("contact:{$clientAddress}", RateLimit::RATE_LIMITS['contact'])) {
            throw RequestError::tooManyRequests('Too many messages from your network. Please try again later.');
        }

        return MailMessages::contactMessage($to, $input);
    }

    private static function trim(string $value): string
    {
        return preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $value) ?? $value;
    }
}
