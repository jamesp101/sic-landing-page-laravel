<?php

namespace Tests\Feature\Auth;

use App\Auth\RateLimit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    /** A browser always declares the length of a form body; signed-out requests without it are refused (RequestPolicy). */
    private const FORM = ['Content-Length' => '100'];

    private function user(string $email = 'member@example.org', string $password = 'correct-password-1'): User
    {
        return User::factory()->create(['email' => $email, 'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4])]);
    }

    public function test_the_login_action_signs_in_and_returns_ok(): void
    {
        $user = $this->user();

        $this->postJson('/_actions/auth.login', ['email' => ' Member@Example.org ', 'password' => 'correct-password-1', 'remember' => false])
            ->assertOk()
            ->assertExactJson(['data' => ['ok' => true]]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_or_unknown_email_is_unauthorized_with_one_message(): void
    {
        $this->user();

        foreach ([['member@example.org', 'wrong-password'], ['nobody@example.org', 'correct-password-1']] as [$email, $password]) {
            $this->postJson('/_actions/auth.login', ['email' => $email, 'password' => $password])
                ->assertStatus(401)
                ->assertJson(['error' => ['type' => 'ActionError', 'code' => 'UNAUTHORIZED', 'message' => 'These credentials do not match our records.']]);
        }
        $this->assertGuest();
    }

    public function test_the_login_action_is_rate_limited_per_email_and_address_and_successes_do_not_count(): void
    {
        $this->user();
        $max = RateLimit::RATE_LIMITS['signInFailuresPerIp']['max'];

        for ($i = 0; $i < $max - 1; $i++) {
            $this->travel(61)->seconds();
            $this->postJson('/_actions/auth.login', ['email' => 'member@example.org', 'password' => 'wrong'])->assertStatus(401);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->travel(61)->seconds();
            $this->postJson('/_actions/auth.login', ['email' => 'member@example.org', 'password' => 'correct-password-1'])->assertOk();
        }
        $this->travel(61)->seconds();
        $this->postJson('/_actions/auth.login', ['email' => 'member@example.org', 'password' => 'wrong'])->assertStatus(401);

        $this->travel(61)->seconds();
        $blocked = $this->postJson('/_actions/auth.login', ['email' => 'MEMBER@example.org', 'password' => 'correct-password-1'])
            ->assertStatus(429)
            ->assertJson(['error' => ['type' => 'ActionError', 'code' => 'TOO_MANY_REQUESTS']]);
        $this->assertStringContainsString('Too many attempts', $blocked->json('error.message'));

        // Another address may still sign in.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/_actions/auth.login', ['email' => 'member@example.org', 'password' => 'correct-password-1'])
            ->assertOk();
    }

    public function test_the_login_page_form_posts_without_javascript_and_redirects_to_the_safe_target(): void
    {
        $user = $this->user();

        $this->post('/login?redirect=/admin/posts', ['email' => 'member@example.org', 'password' => 'correct-password-1', 'remember' => 'on'], self::FORM)
            ->assertStatus(303)
            ->assertRedirect('/admin/posts');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_no_js_post_shows_the_failure_with_the_email_kept_and_the_right_status(): void
    {
        $this->user();

        $this->post('/login', ['email' => 'member@example.org', 'password' => 'wrong'], self::FORM)
            ->assertStatus(422)
            ->assertSee('These credentials do not match our records.')
            ->assertSee('value="member@example.org"', false);
        $this->assertGuest();
    }

    public function test_the_no_js_post_shares_the_sign_in_rate_limit(): void
    {
        $this->user();
        for ($i = 0; $i < RateLimit::RATE_LIMITS['signInFailuresPerIp']['max']; $i++) {
            $this->travel(61)->seconds();
            $this->post('/login', ['email' => 'member@example.org', 'password' => 'wrong'], self::FORM)->assertStatus(422);
        }

        $this->travel(61)->seconds();
        $this->post('/login', ['email' => 'member@example.org', 'password' => 'correct-password-1'], self::FORM)
            ->assertStatus(429)
            ->assertSee('Too many attempts');
        $this->assertGuest();
    }

    public function test_redirect_targets_that_leave_the_site_fall_back_to_the_dashboard(): void
    {
        $user = $this->user();
        foreach (['https://evil.test', '//evil.test', '/\\evil.test', 'evil'] as $target) {
            $this->travel(61)->seconds();
            $this->post('/login?redirect='.urlencode($target), ['email' => 'member@example.org', 'password' => 'correct-password-1'], self::FORM)
                ->assertRedirect('/dashboard');
            $this->post('/logout');
        }
        $this->actingAs($user)->get('/login?redirect='.urlencode('//evil.test'))->assertRedirect('/dashboard');
        $this->actingAs($user)->get('/login?redirect=/admin/posts')->assertRedirect('/admin/posts');
    }

    public function test_the_login_page_hands_the_form_its_props_and_a_no_js_form(): void
    {
        $this->get('/login?redirect=/admin/posts&status=password-reset')
            ->assertOk()
            ->assertSee('data-island="auth/LoginForm"', false)
            ->assertSee('&quot;redirect&quot;:&quot;/admin/posts&quot;', false)
            ->assertSee('Your password has been reset. You can now log in.')
            ->assertSee('method="post"', false);
    }

    public function test_logout_signs_out(): void
    {
        $this->actingAs($this->user())->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_logout_action_signs_out_and_returns_null(): void
    {
        $this->actingAs($this->user())->postJson('/_actions/auth.logout')->assertOk()->assertExactJson(['data' => null]);
        $this->assertGuest();
    }

    public function test_a_signed_out_call_to_a_private_action_is_unauthorized(): void
    {
        $this->postJson('/_actions/account.confirmPassword', ['password' => 'x'])->assertStatus(401);
    }

    public function test_every_sign_in_attempt_counts_against_the_per_address_ceiling_of_5_a_minute(): void
    {
        $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/_actions/auth.login', ['email' => 'member@example.org', 'password' => 'correct-password-1'])->assertOk();
        }

        $this->postJson('/_actions/auth.login', ['email' => 'member@example.org', 'password' => 'correct-password-1'])
            ->assertStatus(429)
            ->assertJson(['error' => ['code' => 'TOO_MANY_REQUESTS']]);
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post('/login', ['email' => 'member@example.org', 'password' => 'correct-password-1'], self::FORM)->assertStatus(429);

        $this->travel(61)->seconds();
        $this->postJson('/_actions/auth.login', ['email' => 'member@example.org', 'password' => 'correct-password-1'])->assertOk();
    }

    public function test_an_accent_variant_the_database_would_match_is_not_the_account(): void
    {
        $user = $this->user('admin@example.org');
        // What a utf8mb4_unicode_ci column does: `lower()` that also folds accents.
        DB::connection()->getPdo()->sqliteCreateFunction('lower', fn ($v) => $v === null ? null : strtolower(Str::ascii($v)), 1);

        $this->postJson('/_actions/auth.login', ['email' => 'ádmin@example.org', 'password' => 'correct-password-1'])->assertStatus(401);
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->postJson('/_actions/auth.login', ['email' => 'ADMIN@example.org', 'password' => 'correct-password-1'])->assertOk();
        $this->assertAuthenticatedAs($user);
    }
}
