<?php

namespace Tests\Feature\Auth;

use App\Auth\PasswordLogin;
use App\Models\User;
use App\Support\Flash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['password' => Hash::make('current-password')]);
    }

    /** Signs in with a real session and returns its id; plants another session of the same User. */
    private function withTwoSessions(User $user): string
    {
        $current = Str::random(40);
        $this->actingAs($user)->withCredentials()->withCookie(config('session.cookie'), $current);
        DB::table('sessions')->insert([
            ['id' => $current, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ]);

        return $current;
    }

    public function test_changing_the_password_ends_the_other_sessions_and_keeps_this_one(): void
    {
        $user = $this->member();
        $current = $this->withTwoSessions($user);

        $this->postJson('/_actions/account.changePassword', [
            'currentPassword' => 'current-password', 'password' => 'brand-new-password', 'passwordConfirmation' => 'brand-new-password',
        ])->assertOk()->assertExactJson(['data' => ['ok' => true]]);

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertSame([$current], DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all());
    }

    public function test_change_password_checks_the_current_password_and_the_input(): void
    {
        $user = $this->member();
        $this->actingAs($user);

        $this->postJson('/_actions/account.changePassword', [
            'currentPassword' => 'wrong', 'password' => 'brand-new-password', 'passwordConfirmation' => 'brand-new-password',
        ])->assertStatus(400)->assertJson(['error' => ['code' => 'BAD_REQUEST', 'message' => 'The provided password was incorrect.']]);

        $this->postJson('/_actions/account.changePassword', [
            'currentPassword' => 'current-password', 'password' => 'short', 'passwordConfirmation' => 'short',
        ])->assertStatus(400)->assertJson(['error' => ['type' => 'ActionInputError', 'fields' => ['password' => ['The password must be at least 8 characters.']]]]);

        $this->postJson('/_actions/account.changePassword', [
            'currentPassword' => 'current-password', 'password' => 'brand-new-password', 'passwordConfirmation' => 'different-one',
        ])->assertStatus(400)->assertJson(['error' => ['fields' => ['passwordConfirmation' => ['The password confirmation does not match.']]]]);

        $this->assertTrue(Hash::check('current-password', $user->fresh()->password));
    }

    public function test_a_name_change_needs_no_password(): void
    {
        $user = $this->member(['email' => 'ana@example.org']);
        $this->actingAs($user);

        $this->postJson('/_actions/account.updateProfile', ['name' => '  Ana Cruz ', 'email' => 'ANA@example.org'])
            ->assertOk()->assertExactJson(['data' => ['emailChanged' => false]])
            ->assertCookie(Flash::COOKIE, Flash::encode('success', 'Profile updated.'));

        $this->assertSame('Ana Cruz', $user->fresh()->name);
        $this->assertTrue($user->fresh()->email_verified);
    }

    public function test_changing_the_email_needs_the_current_password_unverifies_ends_other_sessions_and_warns_the_old_address(): void
    {
        $user = $this->member(['email' => 'old@example.org']);
        $current = $this->withTwoSessions($user);

        $this->postJson('/_actions/account.updateProfile', ['name' => 'Ana', 'email' => 'new@example.org'])
            ->assertStatus(400)
            ->assertJson(['error' => ['message' => 'Enter your current password to change your email address.']]);
        $this->postJson('/_actions/account.updateProfile', ['name' => 'Ana', 'email' => 'new@example.org', 'currentPassword' => 'wrong'])
            ->assertStatus(400);
        $this->assertSame('old@example.org', $user->fresh()->email);

        $this->postJson('/_actions/account.updateProfile', ['name' => 'Ana', 'email' => 'New@Example.org', 'currentPassword' => 'current-password'])
            ->assertOk()->assertExactJson(['data' => ['emailChanged' => true]]);

        $fresh = $user->fresh();
        $this->assertSame('new@example.org', $fresh->email);
        $this->assertFalse($fresh->email_verified);
        $this->assertNull($fresh->email_verified_at);
        $this->assertSame([$current], DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all());

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $mail = $sent[0]->getOriginalMessage();
        $this->assertSame('old@example.org', $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('new@example.org', $mail->getTextBody());
    }

    public function test_an_email_in_use_by_another_user_is_a_conflict(): void
    {
        $this->member(['email' => 'taken@example.org']);
        $this->actingAs($this->member());

        $this->postJson('/_actions/account.updateProfile', ['name' => 'Ana', 'email' => 'taken@example.org'])
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'CONFLICT', 'message' => 'That email address is already in use.']]);
    }

    public function test_profile_input_is_validated_with_the_same_messages(): void
    {
        $this->actingAs($this->member());

        $this->postJson('/_actions/account.updateProfile', ['name' => '   ', 'email' => 'not-an-email'])
            ->assertStatus(400)
            ->assertJson(['error' => ['type' => 'ActionInputError', 'fields' => [
                'name' => ['Name is required.'],
                'email' => ['Enter a valid email address.'],
            ]]]);
    }

    public function test_confirm_password_checks_the_password(): void
    {
        $this->actingAs($this->member());

        $this->postJson('/_actions/account.confirmPassword', ['password' => 'wrong'])->assertStatus(400);
        $this->postJson('/_actions/account.confirmPassword', ['password' => 'current-password'])
            ->assertOk()->assertExactJson(['data' => ['ok' => true]]);
    }

    public function test_deleting_the_account_needs_the_password_signs_out_and_returns_the_redirect(): void
    {
        $this->member(['role' => User::ROLE_SUPERADMIN]);
        $user = $this->member();
        $this->actingAs($user);

        $this->postJson('/_actions/account.deleteAccount', ['password' => 'wrong'])->assertStatus(400);
        $this->assertSame(1, User::query()->whereKey($user->id)->count());

        $this->postJson('/_actions/account.deleteAccount', ['password' => 'current-password'])
            ->assertOk()->assertExactJson(['data' => ['redirect' => '/']]);
        $this->assertSame(0, User::query()->whereKey($user->id)->count());
        $this->assertGuest();
    }

    public function test_the_last_superadmin_cannot_delete_their_account(): void
    {
        $only = $this->member(['role' => User::ROLE_SUPERADMIN]);
        $this->actingAs($only);

        $this->postJson('/_actions/account.deleteAccount', ['password' => 'current-password'])
            ->assertStatus(403)
            ->assertJson(['error' => ['code' => 'FORBIDDEN', 'message' => 'The last superadmin account cannot be deleted.']]);
        $this->assertSame(1, User::query()->whereKey($only->id)->count());
        $this->assertAuthenticatedAs($only);
    }

    public function test_ending_sessions_rotates_the_remember_token(): void
    {
        $user = $this->member();
        DB::table('users')->where('id', $user->id)->update(['remember_token' => 'old-device-token']);

        PasswordLogin::endSessions($user->id, 'whatever');

        $this->assertNotSame('old-device-token', $user->fresh()->remember_token);
        $this->assertSame(60, strlen($user->fresh()->remember_token));
    }

    public function test_the_current_remembered_device_gets_a_fresh_cookie_after_a_password_change(): void
    {
        $user = $this->member();
        $recaller = Auth::guard('web')->getRecallerName();
        $this->actingAs($user)->withCredentials()->withCookie($recaller, 'stale-cookie');

        $response = $this->postJson('/_actions/account.changePassword', [
            'currentPassword' => 'current-password', 'password' => 'brand-new-password', 'passwordConfirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertNotNull($response->getCookie($recaller, false));
        $this->assertNotSame('stale-cookie', $response->getCookie($recaller, false)->getValue());
    }

    public function test_a_device_without_a_remember_cookie_gets_none(): void
    {
        $user = $this->member();
        $this->actingAs($user);

        $response = $this->postJson('/_actions/account.changePassword', [
            'currentPassword' => 'current-password', 'password' => 'brand-new-password', 'passwordConfirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertNull($response->getCookie(Auth::guard('web')->getRecallerName(), false));
    }
}
