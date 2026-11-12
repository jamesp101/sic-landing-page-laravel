<?php

namespace App\Actions;

use App\Auth\AuthSession;
use App\Auth\Mail;
use App\Auth\MailMessages;
use App\Auth\PasswordLogin;
use App\Auth\PasswordPolicy;
use App\Auth\UserRules;
use App\Models\User;
use App\Support\Authz;
use App\Support\Flash;
use App\Support\RequestError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** `account.*`: the signed-in User changes their own profile, password or account. */
class AccountActions
{
    /** `{ name, email, currentPassword? }` → `{ emailChanged }`. The current password is required only when the email changes. */
    public function updateProfile(Request $request): array
    {
        $actor = Authz::requireUser($request->user());
        $request->merge([
            'name' => is_string($request->input('name')) ? trim($request->input('name')) : null,
            'email' => is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : null,
        ]);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:255', 'email:rfc'],
            'currentPassword' => ['nullable', 'string'],
        ], [
            'name.required' => 'Name is required.',
            'email.required' => 'Enter a valid email address.',
            'email.email' => 'Enter a valid email address.',
        ]);

        $taken = User::query()->where('email', $input['email'])->whereKeyNot($actor->id)->exists();
        if ($taken) {
            throw RequestError::conflict('That email address is already in use.');
        }

        $emailChanged = $input['email'] !== mb_strtolower($actor->email);
        // The email is the password-reset target: changing it must not be possible with a session alone.
        if ($emailChanged) {
            if (($input['currentPassword'] ?? '') === '') {
                throw RequestError::badRequest('Enter your current password to change your email address.');
            }
            PasswordLogin::assertPassword($actor->id, $input['currentPassword']);
        }

        $previousEmail = $actor->email;
        $sessionId = AuthSession::currentSessionId($request);
        DB::transaction(function () use ($actor, $input, $emailChanged, $sessionId): void {
            $actor->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
                ...($emailChanged ? ['email_verified' => false, 'email_verified_at' => null] : []),
            ])->save();
            // Sign out every other device; keep this one.
            if ($emailChanged) {
                PasswordLogin::endSessions($actor->id, $sessionId);
            }
        });
        if ($emailChanged) {
            Mail::sendMail(MailMessages::emailChangedMessage($previousEmail, $input['email']));
        }
        Flash::success('Profile updated.');

        return ['emailChanged' => $emailChanged];
    }

    /** `{ currentPassword, password, passwordConfirmation }` → `{ ok: true }`. Ends the User's other sessions. */
    public function changePassword(Request $request): array
    {
        $actor = Authz::requireUser($request->user());
        $input = $request->validate([
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'string', 'min:'.PasswordPolicy::MIN_PASSWORD_LENGTH, 'max:'.PasswordPolicy::MAX_PASSWORD_LENGTH],
            'passwordConfirmation' => ['required', 'string', 'same:password'],
        ], [
            'currentPassword.required' => 'Enter your current password.',
            'password.required' => PasswordPolicy::passwordTooShort(),
            'password.min' => PasswordPolicy::passwordTooShort(),
            'passwordConfirmation.required' => 'The password confirmation does not match.',
            'passwordConfirmation.same' => 'The password confirmation does not match.',
        ]);
        PasswordLogin::assertPassword($actor->id, $input['currentPassword']);

        PasswordLogin::setPasswordLogin($actor->id, Hash::make($input['password']), [
            'endSessions' => ['except' => AuthSession::currentSessionId($request)],
        ]);
        Flash::success('Password updated.');

        return ['ok' => true];
    }

    /** `{ password }` → `{ redirect: '/' }`. Refused for the last Superadmin; a refused delete keeps the User signed in. */
    public function deleteAccount(Request $request): array
    {
        $actor = Authz::requireUser($request->user());
        $input = $request->validate(['password' => ['required', 'string']], ['password.required' => 'Enter your password to confirm.']);
        PasswordLogin::assertPassword($actor->id, $input['password']);

        UserRules::deleteUserWithFiles($actor->id);
        // Only once the account is gone.
        AuthSession::signOut($request);

        return ['redirect' => '/'];
    }

    /** `{ password }` → `{ ok: true }`: the secure-area check behind `/confirm-password`. */
    public function confirmPassword(Request $request): array
    {
        $actor = Authz::requireUser($request->user());
        $input = $request->validate(['password' => ['required', 'string']], ['password.required' => 'Enter your password.']);
        PasswordLogin::assertPassword($actor->id, $input['password']);
        $request->session()->put('auth.password_confirmed_at', time());

        return ['ok' => true];
    }
}
