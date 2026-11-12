<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Authz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    private function runCommand(?string $email, ?string $name, ?string $password, array $options = []): int
    {
        foreach (['ADMIN_EMAIL' => $email, 'ADMIN_NAME' => $name, 'ADMIN_PASSWORD' => $password] as $key => $value) {
            $value === null ? putenv($key) : putenv("{$key}={$value}");
        }

        return $this->artisan('sic:create-admin', $options)->run();
    }

    protected function tearDown(): void
    {
        foreach (['ADMIN_EMAIL', 'ADMIN_NAME', 'ADMIN_PASSWORD'] as $key) {
            putenv($key);
        }
        parent::tearDown();
    }

    public function test_it_creates_a_verified_superadmin(): void
    {
        $this->assertSame(0, $this->runCommand(' Boss@Example.org ', 'The Boss', 'a-long-enough-password'));

        $user = User::query()->where('email', 'boss@example.org')->firstOrFail();
        $this->assertSame('The Boss', $user->name);
        $this->assertTrue(Authz::isSuperAdmin($user));
        $this->assertTrue($user->email_verified);
        $this->assertTrue(Hash::check('a-long-enough-password', $user->password));
    }

    public function test_it_resets_an_existing_user_makes_them_superadmin_and_ends_their_sessions(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.org', 'name' => 'Old Name']);
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->assertSame(0, $this->runCommand('boss@example.org', null, 'a-long-enough-password'));

        $fresh = $user->fresh();
        $this->assertSame('Old Name', $fresh->name);
        $this->assertTrue(Authz::isSuperAdmin($fresh));
        $this->assertTrue(Hash::check('a-long-enough-password', $fresh->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, User::query()->count());
    }

    public function test_it_refuses_a_bad_email_a_missing_name_and_a_short_password(): void
    {
        $this->assertSame(1, $this->runCommand('not-an-email', 'X', 'a-long-enough-password'));
        $this->assertSame(1, $this->runCommand('new@example.org', null, 'a-long-enough-password'));
        $this->assertSame(1, $this->runCommand('new@example.org', 'X', 'only-10-chr'));
        $this->assertSame(0, User::query()->count());
    }

    public function test_if_missing_creates_the_initial_superadmin_only_while_none_exists(): void
    {
        $this->assertSame(0, $this->runCommand('first@example.org', 'First', 'a-long-enough-password', ['--if-missing' => true]));
        $first = User::query()->where('email', 'first@example.org')->firstOrFail();
        $this->assertTrue(Authz::isSuperAdmin($first));

        // A later deploy with other ADMIN_* values neither creates a second Superadmin nor resets the first.
        $this->assertSame(0, $this->runCommand('first@example.org', 'First', 'another-long-password', ['--if-missing' => true]));
        $this->assertSame(0, $this->runCommand('second@example.org', 'Second', 'another-long-password', ['--if-missing' => true]));
        $this->assertSame(1, User::query()->count());
        $this->assertTrue(Hash::check('a-long-enough-password', $first->fresh()->password));
    }

    public function test_if_missing_without_admin_email_skips_but_still_validates_given_values(): void
    {
        $this->assertSame(0, $this->runCommand(null, null, null, ['--if-missing' => true]));
        $this->assertSame(1, $this->runCommand('new@example.org', 'X', 'only-10-chr', ['--if-missing' => true]));
        $this->assertSame(0, User::query()->count());
    }
}
