<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_pages_need_a_signed_in_user(): void
    {
        $this->get('/settings/profile')->assertRedirect('/login?redirect='.urlencode('/settings/profile'));
        $this->get('/confirm-password')->assertRedirect();
    }

    public function test_settings_redirects_to_profile(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings')->assertRedirect('/settings/profile');
    }

    public function test_the_pages_mount_their_islands_with_the_documented_props(): void
    {
        $user = User::factory()->create(['name' => 'Ana Cruz', 'email' => 'ana@example.org', 'email_verified' => false]);
        $this->actingAs($user);

        $this->get('/settings/profile')->assertOk()
            ->assertSee('data-island="settings/ProfileForm"', false)
            ->assertSee('&quot;name&quot;:&quot;Ana Cruz&quot;', false)
            ->assertSee('&quot;emailVerified&quot;:false', false)
            ->assertSee('data-island="DeleteUser"', false);
        $this->get('/settings/security')->assertOk()->assertSee('data-island="settings/PasswordForm"', false);
        $this->get('/settings/appearance')->assertOk()->assertSee('data-island="AppearanceTabs"', false);
        $this->get('/confirm-password?redirect=//evil.test')->assertOk()
            ->assertSee('data-island="auth/ConfirmPasswordForm"', false)
            ->assertSee('&quot;redirect&quot;:&quot;/dashboard&quot;', false);
        $this->get('/forgot-password')->assertOk()->assertSee('data-island="auth/ForgotPasswordForm"', false);
    }
}
