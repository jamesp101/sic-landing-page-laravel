<?php

namespace App\Http\Controllers\Auth;

use App\Auth\AuthSession;
use App\Auth\SafeRedirect;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LoginController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $redirect = SafeRedirect::safeRedirect($request->query('redirect'));
        if ($request->user()) {
            return redirect($redirect);
        }
        $status = $request->query('status') === 'password-reset'
            ? 'Your password has been reset. You can now log in.'
            : '';

        return response()->view('auth.login', ['redirect' => $redirect, 'status' => $status]);
    }

    /**
     * No-JS fallback (or an island that failed to hydrate): the form POSTs here, never GETs, so credentials stay
     * out of the URL. Signs in through `AuthSession::attemptSignIn`, so the same sign-in rate limit applies as for `auth.login`.
     */
    public function store(Request $request): Response|RedirectResponse
    {
        $redirect = SafeRedirect::safeRedirect($request->query('redirect'));
        if ($request->user()) {
            return redirect($redirect);
        }

        $email = trim((string) $request->input('email', ''));
        $failure = AuthSession::attemptSignIn($request, $email, (string) $request->input('password', ''), $request->input('remember') === 'on');
        if ($failure !== null) {
            return response()->view('auth.login', [
                'redirect' => $redirect,
                'email' => $email,
                'error' => $failure['message'],
            ], $failure['status']);
        }

        return redirect($redirect, 303);
    }

    public function destroy(Request $request): RedirectResponse
    {
        AuthSession::signOut($request);

        return redirect('/login', 303);
    }
}
