<?php

namespace Tests\Unit\Platform;

use App\Http\RequestPolicyRules as Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequestPolicyRulesTest extends TestCase
{
    private const CSP = "frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'";

    private const BASE = [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    private const NOINDEX = 'noindex, nofollow';

    private function headersFor(string $path, array $opts = []): array
    {
        return Rules::responseHeaders([
            'method' => $opts['method'] ?? 'GET',
            'url' => 'http://localhost'.$path,
            'signedIn' => $opts['signedIn'] ?? false,
            'siteIsHttps' => $opts['siteHttps'] ?? false,
            'hasCacheControl' => $opts['cc'] ?? false,
            'hasContentSecurityPolicy' => $opts['csp'] ?? false,
        ]);
    }

    public static function headerCases(): array
    {
        $public = ['Cache-Control' => Rules::PUBLIC_CACHE_CONTROL, 'Content-Security-Policy' => self::CSP, ...self::BASE];
        $private = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => self::NOINDEX, 'Content-Security-Policy' => self::CSP, ...self::BASE];
        $noStore = ['Cache-Control' => 'no-store', 'Content-Security-Policy' => self::CSP, ...self::BASE];

        return [
            '/' => ['/', [], $public],
            '/posts' => ['/posts', [], $public],
            '/login' => ['/login', [], $private],
            '/admin/posts' => ['/admin/posts', ['signedIn' => true], $private],
            '/csrf-token' => ['/csrf-token', [], $private],
            '/verify-email/1/abc' => ['/verify-email/1/abc', [], $private],
            '/storage/x.webp' => ['/storage/x.webp', [], self::BASE],
            'upload' => ['/api/admin/uploads/image', ['method' => 'POST', 'signedIn' => true], $private],
            '/sitemap.xml' => ['/sitemap.xml', [], $public],
            'non-GET public page' => ['/contact', ['method' => 'POST'], $noStore],
            'HEAD of a public page (crawlers, uptime checks)' => ['/posts', ['method' => 'HEAD'], $public],
            'signed-in GET on a public page' => ['/posts', ['signedIn' => true], $noStore],
            'asset under /build' => ['/build/assets/a.js', [], ['Content-Security-Policy' => self::CSP, ...self::BASE]],
            'own Cache-Control and CSP' => ['/posts', ['cc' => true, 'csp' => true], self::BASE],
            'action call' => ['/_actions/posts.create', ['method' => 'POST'], $private],
        ];
    }

    #[DataProvider('headerCases')]
    public function test_response_headers(string $path, array $opts, array $expected): void
    {
        $this->assertSame($expected, $this->headersFor($path, $opts));
    }

    public function test_hsts_when_the_request_or_site_url_is_https_never_for_plain_http(): void
    {
        $https = Rules::responseHeaders([
            'method' => 'GET', 'url' => 'https://example.org/', 'signedIn' => false, 'siteIsHttps' => false,
            'hasCacheControl' => false, 'hasContentSecurityPolicy' => false,
        ]);
        $hsts = 'max-age=31536000; includeSubDomains';
        $this->assertSame($hsts, $https['Strict-Transport-Security']);
        // Behind a TLS-terminating proxy the request URL is http://, but SITE_URL says https.
        $this->assertSame($hsts, $this->headersFor('/', ['siteHttps' => true])['Strict-Transport-Security']);
        $this->assertSame($hsts, $this->headersFor('/storage/x.webp', ['siteHttps' => true])['Strict-Transport-Security']);
        $this->assertArrayNotHasKey('Strict-Transport-Security', $this->headersFor('/'));
    }

    public function test_a_page_policy_replaces_the_base_csp(): void
    {
        $headers = Rules::responseHeaders([
            'method' => 'GET', 'url' => 'http://localhost/', 'signedIn' => false, 'siteIsHttps' => false,
            'hasCacheControl' => false, 'hasContentSecurityPolicy' => false, 'contentSecurityPolicy' => 'x',
        ]);
        $this->assertSame('x', $headers['Content-Security-Policy']);
    }

    public function test_html_csp_allows_self_the_nonce_and_the_svelte_handler_hash_only(): void
    {
        $csp = Rules::htmlContentSecurityPolicy('abc123');
        $hash = 'sha256-'.base64_encode(hash('sha256', 'this.__e=event', true));

        $this->assertStringStartsWith(self::CSP.'; ', $csp);
        $this->assertStringContainsString("script-src 'self' 'nonce-abc123' '{$hash}' 'unsafe-hashes'", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    public function test_html_csp_names_the_dev_server_origin_when_given(): void
    {
        $csp = Rules::htmlContentSecurityPolicy('n', 'http://[::1]:5173');
        $this->assertStringContainsString("script-src 'self' http://[::1]:5173 'nonce-n'", $csp);
        $this->assertStringContainsString("style-src 'self' http://[::1]:5173 'unsafe-inline'", $csp);
    }

    public function test_login_redirect_sends_anonymous_visitors_of_protected_routes_keeping_the_target(): void
    {
        $this->assertSame('/login?redirect='.rawurlencode('/admin/posts?page=2'), Rules::loginRedirect('/admin/posts?page=2', false));
        $this->assertStringContainsString('/login?', Rules::loginRedirect('/verify-email', false));
        $this->assertStringContainsString('/login?', Rules::loginRedirect('http://localhost/settings/profile', false));
    }

    public function test_login_redirect_lets_anonymous_reach_public_and_login_routes_and_signed_in_everywhere(): void
    {
        $this->assertNull(Rules::loginRedirect('/', false));
        $this->assertNull(Rules::loginRedirect('/login', false));
        $this->assertNull(Rules::loginRedirect('/admin', true));
    }

    public function test_is_scoped_path_is_true_for_dashboard_admin_and_settings_only(): void
    {
        foreach (['/admin', '/admin/posts', '/dashboard', '/dashboard/x', '/settings/profile'] as $path) {
            $this->assertTrue(Rules::isScopedPath($path), $path);
        }
        foreach (['/', '/posts', '/confirm-password', '/administrators', '/api/admin/uploads/image'] as $path) {
            $this->assertFalse(Rules::isScopedPath($path), $path);
        }
    }

    public function test_rejects_signed_out_rpc_calls_to_every_action_but_the_public_ones(): void
    {
        $rejects = fn (string $method, string $path, bool $signedIn = false) => Rules::rejectsAnonymousAction($method, 'http://localhost'.$path, $signedIn);

        $this->assertTrue($rejects('POST', '/_actions/posts.create'));
        $this->assertTrue($rejects('POST', '/_actions/account.deleteUser/'));
        $this->assertTrue($rejects('GET', '/_actions/users.list'));
        $this->assertTrue($rejects('POST', '/_actions/'));
        $this->assertTrue($rejects('POST', '/_actions'));
        $this->assertFalse($rejects('POST', '/_actions/contact.send'));
        $this->assertFalse($rejects('POST', '/_actions/contact.send/'));
        $this->assertFalse($rejects('POST', '/_actions/auth.login'));
        $this->assertFalse($rejects('POST', '/_actions/auth.forgotPassword'));
        $this->assertFalse($rejects('POST', '/_actions/auth.resetPassword'));
        $this->assertTrue($rejects('POST', '/_actions/auth.logout'));
        $this->assertTrue($rejects('POST', '/_actions/contact.sendAll'));
        $this->assertTrue($rejects('POST', '/_actions/contact.send/../posts.create'));
        $this->assertFalse($rejects('POST', '/contact'));
    }

    public function test_never_rejects_a_signed_in_user(): void
    {
        $this->assertFalse(Rules::rejectsAnonymousAction('POST', '/_actions/posts.create', true));
    }

    public function test_anonymous_action_response_is_a_401_in_the_action_error_envelope(): void
    {
        $response = Rules::anonymousActionResponse();
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            ['error' => ['type' => 'ActionError', 'code' => 'UNAUTHORIZED', 'message' => 'Sign in required.']],
            json_decode((string) $response->getContent(), true),
        );
    }

    public static function bodyCases(): array
    {
        return [
            ['POST', false, '0', false],
            ['POST', false, '512', false],
            ['POST', false, '65536', false],
            ['POST', false, '65537', true],
            ['POST', false, '104857600', true],
            ['POST', false, null, true],
            ['POST', false, '', true],
            ['POST', false, '-1', true],
            ['POST', false, '12abc', true],
            ['PUT', false, null, true],
            ['PATCH', false, '70000', true],
            ['DELETE', false, null, true],
            ['GET', false, null, false],
            ['HEAD', false, null, false],
            ['POST', true, null, false],
            ['POST', true, '104857600', false],
        ];
    }

    #[DataProvider('bodyCases')]
    public function test_rejects_large_anonymous_body(string $method, bool $signedIn, ?string $length, bool $rejected): void
    {
        $this->assertSame($rejected, Rules::rejectsLargeAnonymousBody($method, $signedIn, $length));
    }

    public function test_payload_too_large_is_a_413(): void
    {
        $this->assertSame(413, Rules::payloadTooLargeResponse()->getStatusCode());
    }

    public function test_is_public_page_accepts_pages_a_visitor_navigates_to(): void
    {
        foreach (['/', '/posts', '/posts/enrollment-open', '/departments/cit', '/downloads', '/contact'] as $path) {
            $this->assertTrue(Rules::isPublicPage($path), $path);
        }
    }

    public function test_is_public_page_rejects_private_routes_assets_api_and_feeds(): void
    {
        foreach ([
            '/login', '/forgot-password', '/admin/posts', '/dashboard', '/settings/profile', '/api/auth/session',
            '/_actions/contact.send', '/storage/posts/a.webp', '/build/assets/app.js', '/rss.xml', '/sitemap.xml', '/csrf-token',
        ] as $path) {
            $this->assertFalse(Rules::isPublicPage($path), $path);
        }
    }

    public function test_etag_matches_weakly_over_a_list_and_honours_star(): void
    {
        $this->assertTrue(Rules::etagMatches('W/"a"', 'W/"a"'));
        $this->assertTrue(Rules::etagMatches('"a"', 'W/"a"'));
        $this->assertTrue(Rules::etagMatches('"x", W/"a"', '"a"'));
        $this->assertTrue(Rules::etagMatches('*', 'W/"a"'));
        $this->assertFalse(Rules::etagMatches('W/"b"', 'W/"a"'));
        $this->assertFalse(Rules::etagMatches(null, 'W/"a"'));
        $this->assertFalse(Rules::etagMatches('', 'W/"a"'));
    }
}
