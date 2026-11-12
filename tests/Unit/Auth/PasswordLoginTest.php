<?php

namespace Tests\Unit\Auth;

use App\Auth\PasswordLogin;
use App\Auth\RateLimit;
use App\Models\User;
use App\Support\RequestError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordLoginTest extends TestCase
{
    use RefreshDatabase;

    private function userWithoutLogin(): User
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['password' => null]);

        return $user;
    }

    private function plantSession(int $userId, string $id): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
    }

    private function sessionIds(int $userId): array
    {
        return DB::table('sessions')->where('user_id', $userId)->pluck('id')->all();
    }

    private function codeOf(callable $run): ?string
    {
        try {
            $run();
        } catch (RequestError $error) {
            return $error->errorCode;
        }

        return null;
    }

    public function test_it_stores_the_hash_in_users_password_and_replaces_it(): void
    {
        $user = $this->userWithoutLogin();
        PasswordLogin::setPasswordLogin($user->id, 'hash-1');
        $this->assertSame('hash-1', DB::table('users')->where('id', $user->id)->value('password'));

        PasswordLogin::setPasswordLogin($user->id, 'hash-2');
        $this->assertSame('hash-2', DB::table('users')->where('id', $user->id)->value('password'));
    }

    public function test_it_leaves_sessions_alone_unless_asked_to_end_them(): void
    {
        $user = User::factory()->create();
        $this->plantSession($user->id, 't1');
        PasswordLogin::setPasswordLogin($user->id, 'hash-1');
        $this->assertSame(['t1'], $this->sessionIds($user->id));
    }

    public function test_it_ends_every_session_or_every_one_except_the_callers(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->plantSession($user->id, 'mine');
        $this->plantSession($user->id, 't2');
        $this->plantSession($other->id, 'theirs');

        PasswordLogin::setPasswordLogin($user->id, 'hash-1', ['endSessions' => ['except' => 'mine']]);
        $this->assertSame(['mine'], $this->sessionIds($user->id));

        PasswordLogin::setPasswordLogin($user->id, 'hash-2', ['endSessions' => []]);
        $this->assertSame([], $this->sessionIds($user->id));
        $this->assertSame(['theirs'], $this->sessionIds($other->id));
    }

    public function test_it_joins_the_callers_transaction(): void
    {
        $user = $this->userWithoutLogin();
        try {
            DB::transaction(function () use ($user): void {
                PasswordLogin::setPasswordLogin($user->id, 'hash-1');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('password'));
    }

    public function test_current_password_hash_is_the_stored_hash_or_null(): void
    {
        $this->assertSame('legacy-hash', PasswordLogin::currentPasswordHash(tap(User::factory()->create(), fn (User $u) => DB::table('users')->where('id', $u->id)->update(['password' => 'legacy-hash']))->id));
        $this->assertNull(PasswordLogin::currentPasswordHash($this->userWithoutLogin()->id));
    }

    public function test_a_php_bcrypt_hash_written_before_keeps_working(): void
    {
        $user = User::factory()->create();
        $legacy = password_hash('correct-horse', PASSWORD_BCRYPT);
        $this->assertStringStartsWith('$2y$', $legacy);
        PasswordLogin::setPasswordLogin($user->id, $legacy);

        $this->assertNull($this->codeOf(fn () => PasswordLogin::assertPassword($user->id, 'correct-horse')));
    }

    public function test_assert_password_accepts_the_right_password_and_rejects_a_wrong_one_or_a_user_without_a_login(): void
    {
        $user = User::factory()->create();
        PasswordLogin::setPasswordLogin($user->id, Hash::make('correct-horse'));

        $this->assertNull($this->codeOf(fn () => PasswordLogin::assertPassword($user->id, 'correct-horse')));
        $this->assertSame(RequestError::BAD_REQUEST, $this->codeOf(fn () => PasswordLogin::assertPassword($user->id, 'wrong')));
        $none = $this->userWithoutLogin();
        $this->assertSame(RequestError::BAD_REQUEST, $this->codeOf(fn () => PasswordLogin::assertPassword($none->id, 'anything')));
    }

    public function test_assert_password_stops_checking_after_too_many_attempts(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < RateLimit::RATE_LIMITS['password']['max']; $i++) {
            $this->assertSame(RequestError::BAD_REQUEST, $this->codeOf(fn () => PasswordLogin::assertPassword($user->id, 'wrong')));
        }
        $this->assertSame(RequestError::TOO_MANY_REQUESTS, $this->codeOf(fn () => PasswordLogin::assertPassword($user->id, 'wrong')));
    }
}
