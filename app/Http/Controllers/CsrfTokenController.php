<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * `GET /csrf-token`: the CSRF token of the visitor's session. Public pages are cacheable and cookie-free, so they
 * carry no `<meta name="csrf-token">`; a script that has to POST (the contact form) fetches the token here first.
 * The request policy answers it `no-store`, and the session cookie it starts is the one the POST then sends.
 */
class CsrfTokenController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['token' => csrf_token()]);
    }
}
