<?php

namespace App\Http\Middleware;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Response headers that refuse cookies. The session and XSRF middleware run outside `RequestPolicy` and attach their
 * cookies after it returns, so a cacheable response swaps its header bag for this one: a shared cache must never
 * store a `Set-Cookie` that belongs to one visitor.
 */
final class CookielessHeaderBag extends ResponseHeaderBag
{
    public function setCookie(Cookie $cookie): void {}
}
