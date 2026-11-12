<?php

namespace Tests\Feature\Auth;

use App\Auth\RateLimit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class VerifyEmailTest extends TestCase
{
    use RefreshDatabase;

    private function unverified(): User
    {
        return User::factory()->create(['email_verified' => false, 'email_verified_at' => null]);
    }

    public function test_the_notice_page_is_for_signed_in_unverified_users_only(): void
    {
        $this->get('/verify-email')->assertRedirect('/login?redirect='.urlencode('/verify-email'));

        $user = $this->unverified();
        $this->actingAs($user)->get('/verify-email')
            ->assertOk()
            ->assertSee('data-island="auth/VerifyEmailForm"', false)
            ->assertSee(e(json_encode($user->email, JSON_UNESCAPED_SLASHES)), false);

        $this->actingAs(User::factory()->create())->get('/verify-email')->assertRedirect('/dashboard');
    }

    public function test_the_mailed_signed_link_verifies_the_address(): void
    {
        $user = $this->unverified();
        $this->actingAs($user)->postJson('/_actions/auth.sendVerificationEmail')->assertOk()->assertExactJson(['data' => null]);

        $mail = Mail::mailer('array')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
        $this->assertSame('Verify your email address', $mail->getSubject());
        preg_match('#https?://\S+/verify-email/\S+#', $mail->getTextBody(), $match);

        // The origin is the configured one, and the signature holds whatever Host the click arrives on.
        $this->assertStringStartsWith(rtrim(config('sic.site_url'), '/').'/verify-email/', $match[0]);
        $this->get(str_replace(rtrim(config('sic.site_url'), '/'), 'http://behind-a-proxy.test', $match[0]))->assertRedirect('/dashboard');
        $fresh = $user->fresh();
        $this->assertTrue($fresh->email_verified);
        $this->assertNotNull($fresh->email_verified_at);
    }

    public function test_a_tampered_other_users_or_stale_address_link_is_refused(): void
    {
        $user = $this->unverified();
        $this->actingAs($user);
        $hash = sha1(mb_strtolower($user->email));

        $sign = fn (array $parameters, $expires = null) => URL::temporarySignedRoute('verification.verify', $expires ?? now()->addHour(), $parameters, absolute: false);
        $this->get($sign(['id' => $user->id, 'hash' => $hash]).'x')->assertForbidden();
        $this->get($sign(['id' => $user->id + 1, 'hash' => $hash]))->assertForbidden();
        $this->get($sign(['id' => $user->id, 'hash' => sha1('other@example.org')]))->assertForbidden();
        $this->get($sign(['id' => $user->id, 'hash' => $hash], now()->subMinute()))->assertForbidden();
        $this->assertFalse($user->fresh()->email_verified);
    }

    public function test_requesting_the_mail_is_rate_limited_per_address_and_skipped_when_verified(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/_actions/auth.sendVerificationEmail')->assertOk();
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());

        $this->actingAs($this->unverified());
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/_actions/auth.sendVerificationEmail')->assertOk();
        }
        $this->postJson('/_actions/auth.sendVerificationEmail')
            ->assertStatus(429)
            ->assertJson(['error' => ['code' => 'TOO_MANY_REQUESTS']]);
        $this->travel(61)->seconds();
        $this->postJson('/_actions/auth.sendVerificationEmail')->assertOk();
        $this->assertSame(RateLimit::RATE_LIMITS['mailRequestPerIp'], ['max' => 3, 'window' => 60]);
    }
}
