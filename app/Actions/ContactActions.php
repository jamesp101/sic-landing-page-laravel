<?php

namespace App\Actions;

use App\Auth\Mail;
use App\Site\Contact;
use Illuminate\Http\Request;

/** `contact.send` (public): the contact form of `/contact`, JSON/multipart from the island or a plain form POST. */
class ContactActions
{
    /** @return array{sent: true} */
    public function send(Request $request): array
    {
        $input = Contact::input($request->only(['name', 'email', 'subject', 'message', 'website']));
        $mail = Contact::contactMail($input, (string) $request->ip(), (string) config('sic.contact_to'));
        if ($mail) {
            Mail::sendMail($mail);
        }

        return ['sent' => true];
    }
}
