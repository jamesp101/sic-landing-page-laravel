<?php

namespace App\Http\Middleware;

use App\Actions\ActionRegistry;
use App\Http\RequestPolicyRules as Rules;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies `RequestPolicyRules` (`web` group, innermost): the login redirect, the 401/413 answers for signed-out
 * requests, route-class headers (Cache-Control, noindex), the CSP with the page nonce, security headers, the
 * weak ETag of anonymous public HTML and the `sic_staff` hint cookie. Anonymous cacheable responses and assets
 * leave without any cookie (`CookielessHeaderBag`) and without a stored session.
 */
class RequestPolicy
{
    /** Request attribute: the `session.driver` to restore after the response. */
    private const SESSION_DRIVER_ATTRIBUTE = 'sic.restore_session_driver';

    public function handle(Request $request, Closure $next): Response
    {
        $url = $request->fullUrl();
        $method = $request->method();
        $pathname = $request->getPathInfo();
        $isAsset = Rules::isAssetPath($pathname);

        // Before the view renders, so `@vite` tags and the inline theme script carry the nonce of this response.
        $nonce = $isAsset ? null : Vite::useCspNonce();
        $signedIn = ! $isAsset && $request->user() !== null;

        // Before the action runs: a signed-out visitor never reaches an action and never makes the server take a large body.
        $rejected = Rules::rejectsAnonymousAction($method, $url, $signedIn, ActionRegistry::isPublic(...))
            ? Rules::anonymousActionResponse()
            : (Rules::rejectsLargeAnonymousBody($method, $signedIn, $request->headers->get('Content-Length'))
                ? Rules::payloadTooLargeResponse()
                : null);

        $redirect = Rules::loginRedirect($url, $signedIn);
        if ($redirect !== null) {
            return redirect($redirect, 302);
        }

        $response = $rejected ?? $next($request);

        $isHtml = str_starts_with((string) $response->headers->get('Content-Type'), 'text/html');
        $headers = Rules::responseHeaders([
            'method' => $method,
            'url' => $url,
            'signedIn' => $signedIn,
            'siteIsHttps' => str_starts_with((string) config('sic.site_url'), 'https:'),
            'hasCacheControl' => $this->hasOwnCacheControl($response),
            'hasContentSecurityPolicy' => $response->headers->has('Content-Security-Policy'),
            ...($isHtml && $nonce !== null ? ['contentSecurityPolicy' => Rules::htmlContentSecurityPolicy($nonce, $this->viteDevOrigin())] : []),
        ]);
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        $cacheControl = (string) $response->headers->get('Cache-Control');
        if ($isAsset || (! $signedIn && preg_match('/(^|[\s,])public\b/i', $cacheControl) === 1)) {
            $this->withoutCookies($request, $response);
        }
        if ($method === 'GET' && $response->getStatusCode() === 200 && ! $signedIn && $isHtml && ! $isAsset) {
            $response = $this->conditional($request, $response, $nonce);
        }
        if (! $isAsset) {
            $this->staffHint($request, $response, $signedIn);
        }

        return $response;
    }

    /** Puts back the session driver `withoutCookies` replaced (the process may serve more requests). */
    public function terminate(Request $request, Response $response): void
    {
        $driver = $request->attributes->get(self::SESSION_DRIVER_ATTRIBUTE);
        if ($driver !== null) {
            config(['session.driver' => $driver]);
        }
    }

    /** Symfony fills `Cache-Control` with `no-cache, private` when a response sets none. */
    private function hasOwnCacheControl(Response $response): bool
    {
        $value = (string) $response->headers->get('Cache-Control');

        return $value !== '' && $value !== 'no-cache, private';
    }

    private function viteDevOrigin(): ?string
    {
        if (! app()->isLocal() || ! Vite::isRunningHot()) {
            return null;
        }
        $parts = parse_url(trim((string) file_get_contents(Vite::hotFile())));
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * No cookie on this response, and no session row for a visitor who arrived without one: the stored session
     * would be unreachable (the cookie that names it is never sent). StartSession saves whatever driver is
     * configured when the response leaves, so a throwaway `array` store takes its place.
     */
    private function withoutCookies(Request $request, Response $response): void
    {
        $response->headers = new CookielessHeaderBag($response->headers->allPreserveCaseWithoutCookies());
        if (! $request->cookies->has((string) config('session.cookie'))) {
            $request->attributes->set(self::SESSION_DRIVER_ATTRIBUTE, config('session.driver'));
            config(['session.driver' => 'array']);
        }
    }

    /**
     * A weak ETag over the body of anonymous public HTML (the CSP nonce left out: it changes on every request),
     * answered with 304 for a matching `If-None-Match`. A 304 carries no `Content-Security-Policy`, so the
     * client keeps the policy (and nonce) of the copy it already holds.
     */
    private function conditional(Request $request, Response $response, ?string $nonce): Response
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $body = $response->getContent();
        if (! is_string($body) || preg_match('/(^|[\s,])public\b/i', $cacheControl) !== 1) {
            return $response;
        }
        $stable = $nonce === null ? $body : str_replace($nonce, '', $body);
        $etag = sprintf('W/"%x-%s"', strlen($stable), substr(rtrim(strtr(base64_encode(sha1($stable, true)), '+/', '-_'), '='), 0, 22));
        $response->headers->set('ETag', $etag);
        if (Rules::etagMatches($request->headers->get('If-None-Match'), $etag)) {
            $response->setNotModified();
            $response->headers->remove('Content-Security-Policy');
        }

        return $response;
    }

    /** Mirrors the (httpOnly) session cookie into a value-free cookie scripts can read; only on `no-store` responses. */
    private function staffHint(Request $request, Response $response, bool $signedIn): void
    {
        $action = Rules::staffHintAction([
            'signedIn' => $signedIn,
            // The raw header: the hint is never encrypted, so EncryptCookies' decrypt step may have nulled the parsed value.
            'hinted' => $request->cookies->has(Rules::STAFF_HINT_COOKIE) || Rules::hasStaffHint((string) $request->headers->get('Cookie')),
            'cacheControl' => $response->headers->get('Cache-Control'),
        ]);
        if ($action === null) {
            return;
        }
        $secure = str_starts_with((string) config('sic.site_url'), 'https:');
        $cookie = $action === 'set'
            ? Cookie::create(Rules::STAFF_HINT_COOKIE, '1', time() + Rules::STAFF_HINT_MAX_AGE, '/', null, $secure, false, false, Cookie::SAMESITE_LAX)
            : Cookie::create(Rules::STAFF_HINT_COOKIE, null, 1, '/', null, $secure, false, false, Cookie::SAMESITE_LAX);
        // A raw header, not `setCookie`: EncryptCookies encrypts every cookie in the bag, and scripts read this one as plain `1`.
        $response->headers->set('Set-Cookie', (string) $cookie, false);
    }
}
