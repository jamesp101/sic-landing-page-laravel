<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Pure request policy: route classes, sign-in guard, response headers, the `sic_staff` hint and cookie parsing.
 * Port of `request-policy.ts`, `staff-hint.ts` and `cookie-header.ts`. A "URL" argument is a path with an optional
 * query (`/admin/posts?page=2`) or an absolute URL; only its path, query and scheme are read.
 */
final class RequestPolicyRules
{
    /** Routes that need a session: anonymous visitors are sent to the login. */
    private const PROTECTED = [
        '#^/dashboard(/|$)#',
        '#^/admin(/|$)#',
        '#^/settings(/|$)#',
        '#^/confirm-password$#',
        '#^/verify-email(/|$)#',
    ];

    /** Admin-scoped routes: their request context carries the Admin scope. */
    private const SCOPED = ['#^/dashboard(/|$)#', '#^/admin(/|$)#', '#^/settings(/|$)#'];

    /** `/csrf-token` answers per visitor: never cacheable, never indexed. */
    private const PRIVATE = [
        ...self::PROTECTED,
        '#^/api/#',
        '#^/_actions(/|$)#',
        '#^/login$#',
        '#^/forgot-password$#',
        '#^/reset-password$#',
        '#^/csrf-token$#',
    ];

    /** Actions a signed-out visitor may call; every other action needs a session (keep in step with `ActionController::PUBLIC`). */
    public const PUBLIC_ACTIONS = ['contact.send', 'auth.login', 'auth.forgotPassword', 'auth.resetPassword'];

    private const ACTION_RPC_PREFIX = '/_actions/';

    /**
     * Directives that hold for every response. HTML pages get them inside the page policy
     * (`htmlContentSecurityPolicy`); every other response (JSON, endpoints, redirects, errors) gets them alone.
     */
    public const BASE_CSP_DIRECTIVES = [
        "frame-ancestors 'none'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
    ];

    /** Largest body a signed-out visitor may send: the contact form, the login form and the auth actions are far below it. */
    public const ANONYMOUS_BODY_LIMIT = 64 * 1024;

    /** Cacheable public pages: browsers and shared caches keep them 60 s, caches may serve a stale copy for 10 minutes while revalidating. */
    public const PUBLIC_CACHE_CONTROL = 'public, max-age=60, s-maxage=60, stale-while-revalidate=600';

    /**
     * `sic_staff`: a JS-readable presence hint for a signed-in browser. The session cookie is httpOnly, so a
     * script cannot see it; the middleware mirrors its presence into this value-free cookie (set while a session
     * exists, deleted once a private response shows there is none; `staffHintAction` decides from the final
     * response). Anonymous HTML may be served from a shared cache to a signed-in person, so the public prefetch
     * script reads it to stay quiet for them. It carries no identity and grants nothing.
     */
    public const STAFF_HINT_COOKIE = 'sic_staff';

    public const STAFF_HINT_MAX_AGE = 60 * 60 * 24 * 7;

    /** The Svelte `<img onload="this.__e=event">` handler, allowed as an attribute only (see `htmlContentSecurityPolicy`). */
    public const SVELTE_IMG_EVENT_HANDLER = 'this.__e=event';

    private static function matches(array $list, string $pathname): bool
    {
        foreach ($list as $pattern) {
            if (preg_match($pattern, $pathname) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function pathOf(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_PATH) ?? '/');
    }

    /** Static assets: no session, no Cache-Control from the policy. */
    public static function isAssetPath(string $pathname): bool
    {
        return str_starts_with($pathname, '/build/') || str_starts_with($pathname, '/storage/');
    }

    /**
     * A public HTML page: not private, not an asset, not a feed. Anonymous responses for these are cacheable
     * (`PUBLIC_CACHE_CONTROL`), so the public hover prefetch only targets them.
     */
    public static function isPublicPage(string $pathname): bool
    {
        return ! self::matches(self::PRIVATE, $pathname) && ! self::isAssetPath($pathname) && ! str_ends_with($pathname, '.xml');
    }

    public static function isPrivatePath(string $pathname): bool
    {
        return self::matches(self::PRIVATE, $pathname);
    }

    /** An Admin-scoped route (`/dashboard`, `/admin`, `/settings`). */
    public static function isScopedPath(string $pathname): bool
    {
        return self::matches(self::SCOPED, $pathname);
    }

    /**
     * True for an action call from a signed-out visitor that is not a public action. The middleware answers it
     * before the action runs, so anonymous clients cannot make the server buffer or parse uploads.
     */
    public static function rejectsAnonymousAction(string $method, string $url, bool $signedIn): bool
    {
        if ($signedIn) {
            return false;
        }
        $pathname = self::pathOf($url);
        if ($pathname !== '/_actions' && ! str_starts_with($pathname, self::ACTION_RPC_PREFIX)) {
            return false;
        }
        $name = preg_replace('#/$#', '', substr($pathname, strlen(self::ACTION_RPC_PREFIX)));

        return ! in_array($name, self::PUBLIC_ACTIONS, true);
    }

    /** Weak match of one ETag against an `If-None-Match` value (a list; `*` matches anything). */
    public static function etagMatches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || $ifNoneMatch === '') {
            return false;
        }
        $strip = fn (string $tag): string => preg_replace('#^W/#', '', trim($tag));
        $wanted = $strip($etag);
        foreach (explode(',', $ifNoneMatch) as $tag) {
            if (trim($tag) === '*' || $strip($tag) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /** The 401 `rejectsAnonymousAction` is answered with: the envelope the action client turns into an `ActionError`. */
    public static function anonymousActionResponse(): JsonResponse
    {
        return new JsonResponse(['error' => ['type' => 'ActionError', 'code' => 'UNAUTHORIZED', 'message' => 'Sign in required.']], 401);
    }

    /**
     * True for a signed-out request that can carry a body (anything but GET/HEAD) and does not declare a
     * `Content-Length` of at most `ANONYMOUS_BODY_LIMIT` bytes (missing, malformed or larger). Every upload route
     * and every signed-in action needs a session, so nothing a signed-out visitor legitimately sends is exempt.
     */
    public static function rejectsLargeAnonymousBody(string $method, bool $signedIn, ?string $contentLength): bool
    {
        if ($signedIn || $method === 'GET' || $method === 'HEAD') {
            return false;
        }
        if ($contentLength === null || preg_match('/^\d+$/', $contentLength) !== 1) {
            return true;
        }

        return (float) $contentLength > self::ANONYMOUS_BODY_LIMIT;
    }

    /** The 413 `rejectsLargeAnonymousBody` is answered with. */
    public static function payloadTooLargeResponse(): Response
    {
        return new Response('Request body too large.', 413, ['Content-Type' => 'text/plain']);
    }

    /** Before rendering: where to send an anonymous visitor of a protected route (login, keeping the target), else null. */
    public static function loginRedirect(string $url, bool $signedIn): ?string
    {
        $pathname = self::pathOf($url);
        if ($signedIn || ! self::matches(self::PROTECTED, $pathname)) {
            return null;
        }
        $query = parse_url($url, PHP_URL_QUERY);

        return '/login?redirect='.rawurlencode($pathname.($query === null || $query === '' ? '' : '?'.$query));
    }

    /**
     * The `Content-Security-Policy` of an HTML page: the base directives plus scripts only from this origin, the
     * page's nonce (the `@vite` tags and the inline theme script carry it) and Svelte's one inline event
     * attribute, which `'unsafe-hashes'` allows by hash alone. Styles stay inline-friendly: components position
     * slide layers with `style=` attributes. `$devOrigin` is the Vite dev server, only passed in local development
     * (its websocket needs no entry: there is no `connect-src`).
     */
    public static function htmlContentSecurityPolicy(string $nonce, ?string $devOrigin = null): string
    {
        $dev = $devOrigin === null ? '' : ' '.$devOrigin;
        $handlerHash = 'sha256-'.base64_encode(hash('sha256', self::SVELTE_IMG_EVENT_HANDLER, true));

        return implode('; ', [
            ...self::BASE_CSP_DIRECTIVES,
            "script-src 'self'{$dev} 'nonce-{$nonce}' '{$handlerHash}' 'unsafe-hashes'",
            "style-src 'self'{$dev} 'unsafe-inline'",
        ]);
    }

    /**
     * Headers to set on the response (override any existing value). `$input`: `method`, `url`, `signedIn`,
     * `siteIsHttps` (`SITE_URL` is https: HSTS is sent even when a TLS-terminating proxy makes the request look
     * like http), `hasCacheControl` and `hasContentSecurityPolicy` (the response already carries its own), and
     * optionally `contentSecurityPolicy` (the policy to send instead of the base directives, for HTML).
     *
     * @param  array{method: string, url: string, signedIn: bool, siteIsHttps: bool, hasCacheControl: bool, hasContentSecurityPolicy: bool, contentSecurityPolicy?: string}  $input
     * @return array<string, string>
     */
    public static function responseHeaders(array $input): array
    {
        $pathname = self::pathOf($input['url']);
        $headers = [];

        if (! $input['hasCacheControl'] && ! self::isAssetPath($pathname)) {
            $isPrivate = self::matches(self::PRIVATE, $pathname) || $input['signedIn'] || ! in_array($input['method'], ['GET', 'HEAD'], true);
            $headers['Cache-Control'] = $isPrivate ? 'no-store' : self::PUBLIC_CACHE_CONTROL;
        }
        if (self::matches(self::PRIVATE, $pathname)) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
        }

        // No page may be framed. /storage sets its own per-type policy (PDFs must stay viewable).
        if (! str_starts_with($pathname, '/storage/') && ! $input['hasContentSecurityPolicy']) {
            $headers['Content-Security-Policy'] = $input['contentSecurityPolicy'] ?? implode('; ', self::BASE_CSP_DIRECTIVES);
        }
        $headers['X-Frame-Options'] = 'DENY';
        $headers['X-Content-Type-Options'] = 'nosniff';
        $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
        if (parse_url($input['url'], PHP_URL_SCHEME) === 'https' || $input['siteIsHttps']) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    /** The value of cookie `$name` in a `Cookie` header / `document.cookie` string (URI-decoded when it can be), else null. */
    public static function cookieValue(?string $cookieHeader, string $name): ?string
    {
        foreach (explode(';', $cookieHeader ?? '') as $part) {
            $separator = strpos($part, '=');
            if ($separator === false || trim(substr($part, 0, $separator)) !== $name) {
                continue;
            }
            $raw = trim(substr($part, $separator + 1));
            if (preg_match('/%(?![0-9A-Fa-f]{2})/', $raw) === 1) {
                return $raw;
            }
            $decoded = rawurldecode($raw);

            return mb_check_encoding($decoded, 'UTF-8') ? $decoded : $raw;
        }

        return null;
    }

    /** Whether a `document.cookie` / `Cookie` header string carries the hint. */
    public static function hasStaffHint(string $cookieHeader): bool
    {
        return self::cookieValue($cookieHeader, self::STAFF_HINT_COOKIE) !== null;
    }

    /**
     * What the middleware does with the hint once the response is final: `set` for a signed-in person without it,
     * `delete` for a signed-out one who still has it, and only when the response is `no-store`: a shared cache must
     * never store a `Set-Cookie` that belongs to one visitor.
     *
     * @param  array{signedIn: bool, hinted: bool, cacheControl: ?string}  $input
     * @return 'set'|'delete'|null
     */
    public static function staffHintAction(array $input): ?string
    {
        if (preg_match('/(^|[\s,])no-store\b/i', $input['cacheControl'] ?? '') !== 1) {
            return null;
        }
        if ($input['signedIn'] && ! $input['hinted']) {
            return 'set';
        }

        return ! $input['signedIn'] && $input['hinted'] ? 'delete' : null;
    }
}
