<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function sent(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    /** @return array{token: string, email: string} */
    private function requestLink(string $email): array
    {
        $this->postJson('/_actions/auth.forgotPassword', ['email' => $email])->assertOk()->assertExactJson(['data' => null]);
        $mail = $this->sent()[0]->getOriginalMessage();
        $this->assertSame('Reset your San Isidro College password', $mail->getSubject());
        preg_match('#/reset-password\?(\S+)#', $mail->getTextBody(), $match);
        parse_str($match[1], $query);

        return $query;
    }

    public function test_forgot_password_always_succeeds_and_mails_only_a_known_user(): void
    {
        $this->postJson('/_actions/auth.forgotPassword', ['email' => 'nobody@example.org'])->assertOk()->assertExactJson(['data' => null]);
        $this->assertSame([], $this->sent());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        User::factory()->create(['email' => 'member@example.org']);
        $query = $this->requestLink(' Member@Example.org ');
        $this->assertSame('member@example.org', $query['email']);
        $this->assertNotSame('', $query['token']);
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
    }

    public function test_the_link_resets_the_password_ends_every_session_and_works_once(): void
    {
        $user = User::factory()->create(['email' => 'member@example.org', 'password' => Hash::make('old-password-1')]);
        DB::table('sessions')->insert([
            ['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 's2', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $link = $this->requestLink('member@example.org');

        $this->postJson('/_actions/auth.resetPassword', $link + ['password' => 'new-password-2'])
            ->assertOk()->assertExactJson(['data' => null]);

        $this->assertTrue(Hash::check('new-password-2', $user->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        $this->postJson('/_actions/auth.resetPassword', $link + ['password' => 'another-password-3'])
            ->assertStatus(400)
            ->assertJson(['error' => ['code' => 'BAD_REQUEST', 'message' => 'This password reset link is invalid or has expired. Request a new one.']]);
        $this->postJson('/_actions/auth.login', ['email' => 'member@example.org', 'password' => 'new-password-2'])->assertOk();
    }

    public function test_a_wrong_token_or_a_short_password_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'member@example.org', 'password' => Hash::make('old-password-1')]);
        $link = $this->requestLink('member@example.org');

        $this->postJson('/_actions/auth.resetPassword', ['token' => 'wrong', 'email' => $link['email'], 'password' => 'new-password-2'])->assertStatus(400);
        $this->postJson('/_actions/auth.resetPassword', $link + ['password' => 'short'])
            ->assertStatus(400)
            ->assertJson(['error' => ['type' => 'ActionInputError', 'fields' => ['password' => ['The password must be at least 8 characters.']]]]);
        $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password));
    }

    public function test_the_reset_page_gets_token_and_email_or_shows_the_invalid_notice(): void
    {
        $this->get('/reset-password?token=abc&email=member%40example.org')
            ->assertOk()
            ->assertSee('data-island="auth/ResetPasswordForm"', false)
            ->assertSee('&quot;token&quot;:&quot;abc&quot;', false)
            ->assertSee('&quot;email&quot;:&quot;member@example.org&quot;', false);

        foreach (['/reset-password', '/reset-password?email=a%40b.c', '/reset-password?token=abc&email=a%40b.c&error=INVALID_TOKEN'] as $url) {
            $this->get($url)->assertOk()->assertSee('This password reset link is invalid or has expired.')->assertDontSee('auth/ResetPasswordForm');
        }
    }

    public function test_forgot_password_allows_3_a_minute_per_address_and_reset_100(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/_actions/auth.forgotPassword', ['email' => 'nobody@example.org'])->assertOk();
        }
        $this->postJson('/_actions/auth.forgotPassword', ['email' => 'nobody@example.org'])
            ->assertStatus(429)->assertJson(['error' => ['code' => 'TOO_MANY_REQUESTS']]);

        // Its own counter: reset is not affected by forgot, and has the 100 a minute ceiling.
        for ($i = 0; $i < 100; $i++) {
            $this->postJson('/_actions/auth.resetPassword', ['token' => 't', 'email' => 'a@b.c', 'password' => 'long-enough-1'])->assertStatus(400);
        }
        $this->postJson('/_actions/auth.resetPassword', ['token' => 't', 'email' => 'a@b.c', 'password' => 'long-enough-1'])
            ->assertStatus(429);

        $this->travel(61)->seconds();
        $this->postJson('/_actions/auth.forgotPassword', ['email' => 'nobody@example.org'])->assertOk();
    }

    public function test_the_reset_link_points_at_the_configured_origin_not_the_request_host(): void
    {
        User::factory()->create(['email' => 'member@example.org']);
        $this->withHeader('Host', 'evil.test')->postJson('/_actions/auth.forgotPassword', ['email' => 'member@example.org'])->assertOk();

        $text = $this->sent()[0]->getOriginalMessage()->getTextBody();
        $this->assertStringContainsString(rtrim(config('sic.site_url'), '/').'/reset-password?token=', $text);
        $this->assertStringNotContainsString('evil.test', $text);
    }
}
