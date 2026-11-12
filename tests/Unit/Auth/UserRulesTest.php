<?php

namespace Tests\Unit\Auth;

use App\Auth\UserRules;
use App\Models\Download;
use App\Models\Post;
use App\Models\User;
use App\Storage\Storage;
use App\Support\RequestError;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;

class UserRulesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
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

    private function inTx(string $check, int $userId): void
    {
        UserRules::superadminTransaction(fn () => UserRules::$check($userId));
    }

    public function test_last_superadmin_checks_refuse_to_delete_or_demote_the_only_superadmin(): void
    {
        $only = $this->user(User::ROLE_SUPERADMIN);
        $this->user(User::ROLE_DEPARTMENT_USER);
        $this->assertSame(RequestError::FORBIDDEN, $this->codeOf(fn () => $this->inTx('assertUserDeletable', $only->id)));
        $this->assertSame(RequestError::FORBIDDEN, $this->codeOf(fn () => $this->inTx('assertUserDemotable', $only->id)));
    }

    public function test_last_superadmin_checks_keep_their_messages(): void
    {
        $only = $this->user(User::ROLE_SUPERADMIN);
        try {
            $this->inTx('assertUserDeletable', $only->id);
            $this->fail('Expected a refusal.');
        } catch (RequestError $error) {
            $this->assertSame('The last superadmin account cannot be deleted.', $error->getMessage());
        }
        try {
            $this->inTx('assertUserDemotable', $only->id);
            $this->fail('Expected a refusal.');
        } catch (RequestError $error) {
            $this->assertSame('The last superadmin cannot be changed to a department user.', $error->getMessage());
        }
    }

    public function test_they_allow_removing_a_superadmin_while_another_remains(): void
    {
        $first = $this->user(User::ROLE_SUPERADMIN);
        $this->user(User::ROLE_SUPERADMIN);
        $this->assertNull($this->codeOf(fn () => $this->inTx('assertUserDeletable', $first->id)));
        $this->assertNull($this->codeOf(fn () => $this->inTx('assertUserDemotable', $first->id)));
    }

    public function test_they_pass_for_a_department_user_and_for_an_unknown_user(): void
    {
        $only = $this->user(User::ROLE_SUPERADMIN);
        $member = $this->user(User::ROLE_DEPARTMENT_USER);
        $this->assertNull($this->codeOf(fn () => $this->inTx('assertUserDeletable', $member->id)));
        $this->assertNull($this->codeOf(fn () => $this->inTx('assertUserDeletable', $only->id + $member->id + 1)));
    }

    public function test_the_second_of_two_sequential_removals_is_refused(): void
    {
        $a = $this->user(User::ROLE_SUPERADMIN);
        $b = $this->user(User::ROLE_SUPERADMIN);
        $demote = fn (User $user) => UserRules::superadminTransaction(function () use ($user): void {
            UserRules::assertUserDemotable($user->id);
            $user->forceFill(['role' => User::ROLE_DEPARTMENT_USER])->save();
        });
        $demote($a);
        $this->assertSame(RequestError::FORBIDDEN, $this->codeOf(fn () => $demote($b)));
        $this->assertSame(User::ROLE_SUPERADMIN, $b->fresh()->role);
    }

    public function test_the_check_and_the_write_commit_or_roll_back_together(): void
    {
        $user = $this->user(User::ROLE_DEPARTMENT_USER);
        $name = $user->name;
        try {
            UserRules::superadminTransaction(function () use ($user): void {
                $user->forceFill(['name' => 'changed'])->save();
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected the error to pass through.');
        } catch (\RuntimeException $error) {
            $this->assertSame('boom', $error->getMessage());
        }
        $this->assertSame($name, $user->fresh()->name);
    }

    public function test_a_write_conflict_or_deadlock_becomes_a_readable_409(): void
    {
        foreach ([new DeadlockException('deadlock'), $this->serializationFailure()] as $conflict) {
            try {
                UserRules::superadminTransaction(function () use ($conflict): void {
                    throw $conflict;
                });
                $this->fail('Expected a conflict.');
            } catch (RequestError $error) {
                $this->assertSame(RequestError::CONFLICT, $error->errorCode);
                $this->assertSame(409, $error->status());
                $this->assertSame('Another change happened at the same time. Try again.', $error->getMessage());
            }
        }
    }

    public function test_other_database_errors_pass_through_unchanged(): void
    {
        $this->expectException(QueryException::class);
        UserRules::superadminTransaction(fn () => DB::select('select * from no_such_table'));
    }

    private function serializationFailure(): QueryException
    {
        $previous = new PDOException('could not serialize access');
        (new ReflectionProperty(\Exception::class, 'code'))->setValue($previous, '40001');

        return new QueryException('sqlite', 'update users', [], $previous);
    }

    private function withFiles(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => $role]);
        Post::unguarded(fn () => Post::query()->create([
            'title' => $email, 'slug' => $email, 'content' => '', 'type' => 'article',
            'highlight_image_path' => "posts/{$email}.webp", 'user_id' => $user->id,
        ]));
        Download::unguarded(fn () => Download::query()->create([
            'file_name' => 'f.pdf', 'file_path' => "downloads/{$email}.pdf", 'file_size' => 1, 'user_id' => $user->id,
        ]));
        foreach (["posts/{$email}.webp", "downloads/{$email}.pdf"] as $key) {
            Storage::putBytes($key, 'x', 'application/octet-stream');
        }

        return $user;
    }

    private function removeFiles(string $email): void
    {
        Storage::removeAll(["posts/{$email}.webp", "downloads/{$email}.pdf"]);
    }

    public function test_delete_user_with_files_deletes_the_user_and_after_the_commit_their_files(): void
    {
        $this->user(User::ROLE_SUPERADMIN);
        $user = $this->withFiles('gone@example.com', User::ROLE_DEPARTMENT_USER);
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        UserRules::deleteUserWithFiles($user->id);

        $this->assertSame(0, User::query()->whereKey($user->id)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(0, Post::query()->where('user_id', $user->id)->count());
        $this->assertNull(Storage::head('posts/gone@example.com.webp'));
        $this->assertNull(Storage::head('downloads/gone@example.com.pdf'));
    }

    public function test_delete_user_with_files_refuses_the_last_superadmin_and_keeps_the_user_and_their_files(): void
    {
        $only = $this->withFiles('only@example.com', User::ROLE_SUPERADMIN);
        try {
            $this->assertSame(RequestError::FORBIDDEN, $this->codeOf(fn () => UserRules::deleteUserWithFiles($only->id)));
            $this->assertSame(1, User::query()->whereKey($only->id)->count());
            $this->assertNotNull(Storage::head('posts/only@example.com.webp'));
        } finally {
            $this->removeFiles('only@example.com');
        }
    }

    public function test_delete_user_with_files_is_not_found_for_an_unknown_user(): void
    {
        $this->assertSame(RequestError::NOT_FOUND, $this->codeOf(fn () => UserRules::deleteUserWithFiles(999_999)));
    }
}
