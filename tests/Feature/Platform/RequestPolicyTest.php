<?php

namespace Tests\Feature\Platform;

use App\Http\RequestPolicyRules as Rules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The headers per route class, through the whole `web` stack, on probe routes (no other area's pages needed). */
class RequestPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->group(function (): void {
            $page = fn () => response('<!doctype html><html><head><script nonce="'.Vite::cspNonce().'">1</script></head><body>probe</body></html>');
            Route::get('/probe/page', $page);
            Route::get('/admin/probe', $page);
            Route::get('/build/probe', fn () => response('asset', 200, ['Content-Type' => 'text/javascript']));
            Route::get('/api/probe', fn () => response()->json(['ok' => true]));
            Route::post('/probe/post', fn () => response()->json(['ok' => true]));
        });
    }

    private function setCookies(TestResponse $response): array
    {
        return array_map(fn ($cookie) => $cookie->getName(), $response->baseResponse->headers->getCookies());
    }

    private function cacheControl(TestResponse $response): array
    {
        return array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
    }

    public function test_an_anonymous_public_page_is_cacheable_and_carries_no_cookie(): void
    {
        $response = $this->get('/probe/page')->assertOk();

        foreach (['public', 'max-age=60', 's-maxage=60', 'stale-while-revalidate=600'] as $directive) {
            $this->assertContains($directive, $this->cacheControl($response));
        }
        $this->assertSame([], $this->setCookies($response));
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertNull($response->headers->get('X-Robots-Tag'));
        $response->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_html_carries_a_csp_whose_nonce_matches_the_inline_script(): void
    {
        $response = $this->get('/probe/page');
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertSame(1, preg_match('/<script nonce="([^"]+)"/', (string) $response->getContent(), $match));
        $this->assertStringContainsString("script-src 'self' 'nonce-{$match[1]}' ", $csp);
        $this->assertStringContainsString("'unsafe-hashes'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString("'unsafe-inline'; ", explode('style-src', $csp)[0]);
    }

    public function test_each_response_gets_its_own_nonce(): void
    {
        $nonce = fn (TestResponse $r) => preg_match("/'nonce-([^']+)'/", (string) $r->headers->get('Content-Security-Policy'), $m) ? $m[1] : null;

        $this->assertNotSame($nonce($this->get('/probe/page')), $nonce($this->get('/probe/page')));
    }

    public function test_non_html_responses_get_the_base_csp_only(): void
    {
        $csp = (string) $this->get('/api/probe')->headers->get('Content-Security-Policy');

        $this->assertSame("frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'", $csp);
    }

    public function test_a_matching_if_none_match_answers_304_without_a_csp_and_ignores_the_nonce(): void
    {
        $etag = (string) $this->get('/probe/page')->headers->get('ETag');
        $this->assertStringStartsWith('W/"', $etag);

        $again = $this->get('/probe/page', ['If-None-Match' => $etag]);
        $again->assertStatus(304);
        $this->assertSame($etag, $again->headers->get('ETag'));
        $this->assertContains('public', $this->cacheControl($again));
        $this->assertFalse($again->headers->has('Content-Security-Policy'));
        $this->assertSame('', (string) $again->getContent());

        $this->get('/probe/page', ['If-None-Match' => 'W/"other"'])->assertOk();
    }

    public function test_the_login_redirect_keeps_the_target(): void
    {
        $this->get('/admin/probe?page=2')->assertRedirect('/login?redirect='.rawurlencode('/admin/probe?page=2'));
    }

    public function test_an_admin_page_is_no_store_noindex_with_a_csp_and_sets_the_staff_hint(): void
    {
        $this->actingAsSuperadmin();
        $response = $this->get('/admin/probe')->assertOk();

        $this->assertContains('no-store', $this->cacheControl($response));
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString("'nonce-", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('ETag'));
        $this->assertContains(Rules::STAFF_HINT_COOKIE, $this->setCookies($response));
        $response->assertCookie(Rules::STAFF_HINT_COOKIE, '1', false);
    }

    public function test_the_staff_hint_is_not_set_again_and_is_cleared_once_signed_out(): void
    {
        $this->actingAsSuperadmin();
        $hinted = $this->withUnencryptedCookie(Rules::STAFF_HINT_COOKIE, '1')->get('/admin/probe');
        $this->assertNotContains(Rules::STAFF_HINT_COOKIE, $this->setCookies($hinted));

        $this->app['auth']->guard('web')->logout();
        $cleared = $this->withUnencryptedCookie(Rules::STAFF_HINT_COOKIE, '1')->get('/api/probe');
        $cookie = collect($cleared->baseResponse->headers->getCookies())->first(fn ($c) => $c->getName() === Rules::STAFF_HINT_COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isCleared());
    }

    public function test_a_signed_in_visitor_gets_no_store_on_a_public_page(): void
    {
        $this->actingAsSuperadmin();
        $response = $this->get('/probe/page');

        $this->assertContains('no-store', $this->cacheControl($response));
        $this->assertNotContains('public', $this->cacheControl($response));
        $this->assertNull($response->headers->get('X-Robots-Tag'));
    }

    public function test_a_signed_out_action_call_is_401_before_the_action_runs(): void
    {
        $response = $this->postAction('posts.create', ['title' => 'x'], ['Content-Length' => '20']);

        $response->assertStatus(401)->assertExactJson([
            'error' => ['type' => 'ActionError', 'code' => 'UNAUTHORIZED', 'message' => 'Sign in required.'],
        ]);
        $this->assertContains('no-store', $this->cacheControl($response));
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_a_public_action_is_not_answered_with_401(): void
    {
        $this->assertNotSame(401, $this->postAction('contact.send', [], ['Content-Length' => '2'])->getStatusCode());
    }

    public function test_a_signed_out_body_over_64_kb_or_without_content_length_is_413(): void
    {
        $this->withHeaders(['Content-Length' => (string) (Rules::ANONYMOUS_BODY_LIMIT + 1)])->post('/probe/post')->assertStatus(413);
        $this->withoutHeader('Content-Length')->post('/probe/post', [], ['CONTENT_LENGTH' => ''])->assertStatus(413);
        $this->withHeaders(['Content-Length' => (string) Rules::ANONYMOUS_BODY_LIMIT])->post('/probe/post')->assertOk();
    }

    public function test_a_signed_in_user_may_post_a_large_body(): void
    {
        $this->actingAsSuperadmin();
        $this->withHeaders(['Content-Length' => '104857600'])->post('/probe/post')->assertOk();
    }

    public function test_assets_carry_no_cookie_and_no_policy_cache_control(): void
    {
        $response = $this->get('/build/probe')->assertOk();

        $this->assertSame([], $this->setCookies($response));
        $this->assertNotContains('public', $this->cacheControl($response));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_csrf_token_endpoint_returns_the_session_token_uncached(): void
    {
        $response = $this->getJson('/csrf-token')->assertOk();

        $this->assertSame(csrf_token(), $response->json('token'));
        $this->assertContains('no-store', $this->cacheControl($response));
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
