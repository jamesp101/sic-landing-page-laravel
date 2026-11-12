<?php

namespace App\Actions;

use App\Auth\AuthSession;
use App\Auth\Mail;
use App\Auth\MailMessages;
use App\Auth\PasswordLogin;
use App\Auth\PasswordPolicy;
use App\Auth\RateLimit;
use App\Models\User;
use App\Support\Authz;
use App\Support\RequestError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

/** The `auth.*` actions: what the Better Auth client did on the Astro site. There is no sign-up. */
class AuthActions
{
    /** `auth.login` (public): `{ email, password, remember }` → `{ ok: true }`. */
    public function login(Request $request): array
    {
        $input = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);
        AuthSession::signIn($request, $input['email'], $input['password'], (bool) ($input['remember'] ?? false));

        return ['ok' => true];
    }

    /** `auth.logout`: signs the current session out. */
    public function logout(Request $request): null
    {
        AuthSession::signOut($request);

        return null;
    }

    /**
     * `auth.forgotPassword` (public): `{ email }`. Always succeeds, so it does not reveal which addresses have a
     * User. The work (token write, mail) runs after the response, so response time does not reveal it either.
     */
    public function forgotPassword(Request $request): null
    {
        RateLimit::assertAuthRequestQuota($request->ip() ?? 'unknown', 'forgotPassword');
        $input = $request->validate(['email' => ['required', 'string', 'max:255']]);
        $email = mb_strtolower(trim($input['email']));

        defer(function () use ($email): void {
            Password::broker()->sendResetLink(['email' => $email], function (User $user, string $token): void {
                $url = rtrim((string) config('sic.site_url'), '/').'/reset-password?'.http_build_query([
                    'token' => $token,
                    'email' => $user->email,
                ]);
                Mail::sendMail(MailMessages::resetPasswordMessage($user->email, $url));
            });
        });

        return null;
    }

    /** `auth.resetPassword` (public): `{ token, email, password }`. A reset ends every session of the User. */
    public function resetPassword(Request $request): null
    {
        RateLimit::assertAuthRequestQuota($request->ip() ?? 'unknown', 'resetPassword');
        $input = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string'],
            'password' => ['required', 'string', 'min:'.PasswordPolicy::MIN_PASSWORD_LENGTH, 'max:'.PasswordPolicy::MAX_PASSWORD_LENGTH],
        ], [
            'password.min' => PasswordPolicy::passwordTooShort(),
            'password.required' => PasswordPolicy::passwordTooShort(),
        ]);

        $status = Password::broker()->reset(
            ['email' => mb_strtolower(trim($input['email'])), 'token' => $input['token'], 'password' => $input['password']],
            function (User $user, string $password): void {
                PasswordLogin::setPasswordLogin($user->id, Hash::make($password), ['endSessions' => []]);
            },
        );
        if ($status !== Password::PASSWORD_RESET) {
            throw RequestError::badRequest('This password reset link is invalid or has expired. Request a new one.');
        }

        return null;
    }

    /** `auth.sendVerificationEmail`: mails the signed-in User a signed `/verify-email/{id}/{hash}` link. */
    public function sendVerificationEmail(Request $request): null
    {
        $user = Authz::requireUser($request->user());
        if ($user->email_verified) {
            return null;
        }
        RateLimit::assertAuthRequestQuota($request->ip() ?? 'unknown', 'sendVerificationEmail');

        // Signed as a relative URL and prefixed with the configured origin, never the request's Host header
        // (the route verifies with `signed:relative`).
        $url = rtrim((string) config('sic.site_url'), '/').URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1(mb_strtolower($user->email)),
        ], absolute: false);
        Mail::sendMail(MailMessages::verifyEmailMessage($user->email, $url));

        return null;
    }
}
