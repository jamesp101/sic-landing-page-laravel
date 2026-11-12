<?php

namespace App\Http\Controllers\Auth;

use App\Auth\SafeRedirect;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PasswordController extends Controller
{
    public function forgot(): View
    {
        return view('auth.forgot-password');
    }

    /** The reset link is `/reset-password?token=…&email=…`; without both, or with `error`, it is shown as invalid. */
    public function reset(Request $request): View
    {
        $token = $request->query('token');
        $email = $request->query('email');
        $invalid = ! is_string($token) || $token === '' || ! is_string($email) || $email === '' || $request->query->has('error');

        return view('auth.reset-password', [
            'token' => $invalid ? null : $token,
            'email' => $invalid ? '' : $email,
            'invalid' => $invalid,
        ]);
    }

    public function confirm(Request $request): View
    {
        return view('auth.confirm-password', ['redirect' => SafeRedirect::safeRedirect($request->query('redirect'))]);
    }
}
