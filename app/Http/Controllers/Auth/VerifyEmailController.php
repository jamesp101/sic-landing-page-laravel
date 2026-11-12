<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\RequestError;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->email_verified) {
            return redirect('/dashboard');
        }

        return view('auth.verify-email', ['email' => $user->email]);
    }

    /** The signed link from `auth.sendVerificationEmail`: only the User it was sent to, and only for that address. */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = $request->user();
        if ((string) $user->id !== $id || ! hash_equals(sha1(mb_strtolower($user->email)), $hash)) {
            throw RequestError::forbidden();
        }
        if (! $user->email_verified) {
            $user->forceFill(['email_verified' => true, 'email_verified_at' => now()])->save();
        }

        return redirect('/dashboard');
    }
}
