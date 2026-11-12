<?php

namespace Tests\Feature\Auth;

use App\Models\Department;
use App\Models\User;
use App\Support\Flash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsersActionsTest extends TestCase
{
    use RefreshDatabase;

    private function department(string $name = 'Library'): Department
    {
        return Department::unguarded(fn () => Department::query()->create(['name' => $name, 'slug' => strtolower($name)]));
    }

    private function superadmin(): User
    {
        return User::factory()->superadmin()->create();
    }

    public function test_only_a_superadmin_may_call_users_actions(): void
    {
        $member = User::factory()->forDepartment($this->department()->id)->create();
        $other = User::factory()->create();
        $this->actingAs($member);

        foreach ([
            ['users.create', ['name' => 'A', 'email' => 'a@example.org', 'role' => 'superadmin', 'password' => 'long-enough-1']],
            ['users.update', ['id' => $other->id, 'name' => 'A', 'email' => 'a@example.org', 'role' => 'superadmin']],
            ['users.delete', ['id' => $other->id]],
        ] as [$action, $input]) {
            $this->postJson("/_actions/{$action}", $input)
                ->assertStatus(403)
                ->assertJson(['error' => ['code' => 'FORBIDDEN']]);
        }
        $this->assertSame('department_user', $other->fresh()->role);
        $this->assertSame(1, User::query()->whereKey($other->id)->count());
    }

    public function test_a_signed_out_visitor_is_unauthorized(): void
    {
        $this->postJson('/_actions/users.delete', ['id' => 1])->assertStatus(401);
    }

    public function test_create_stores_the_user_with_a_password_login(): void
    {
        $department = $this->department();
        $this->actingAs($this->superadmin());

        $response = $this->postJson('/_actions/users.create', [
            'name' => ' Ana ', 'email' => ' Ana@Example.org ', 'role' => 'department_user', 'departmentId' => $department->id, 'password' => 'long-enough-1',
        ])->assertOk();

        $user = User::query()->where('email', 'ana@example.org')->firstOrFail();
        $response->assertExactJson(['data' => ['id' => $user->id]]);
        $this->assertSame('Ana', $user->name);
        $this->assertSame($department->id, $user->department_id);
        $this->assertTrue(Hash::check('long-enough-1', $user->password));
        $response->assertCookie(Flash::COOKIE, Flash::encode('success', 'User created successfully.'));
    }

    public function test_create_validates_the_role_department_email_and_password(): void
    {
        $this->actingAs($this->superadmin());

        $this->postJson('/_actions/users.create', ['name' => 'A', 'email' => 'a@example.org', 'role' => 'department_user', 'password' => 'long-enough-1'])
            ->assertStatus(400)
            ->assertJson(['error' => ['fields' => ['departmentId' => ['Choose a department for department users.']]]]);
        $this->postJson('/_actions/users.create', ['name' => 'A', 'email' => 'bad', 'role' => 'x', 'password' => 'short'])
            ->assertStatus(400)
            ->assertJson(['error' => ['fields' => [
                'email' => ['Enter a valid email address.'],
                'role' => ['Choose a role.'],
                'password' => ['The password must be at least 8 characters.'],
            ]]]);
        $this->postJson('/_actions/users.create', ['name' => 'A', 'email' => 'a@example.org', 'role' => 'department_user', 'departmentId' => 9999, 'password' => 'long-enough-1'])
            ->assertStatus(400)
            ->assertJson(['error' => ['message' => 'The selected department does not exist.']]);
    }

    public function test_create_refuses_a_taken_email(): void
    {
        $this->actingAs($this->superadmin());
        User::factory()->create(['email' => 'taken@example.org']);

        $this->postJson('/_actions/users.create', ['name' => 'A', 'email' => 'Taken@example.org', 'role' => 'superadmin', 'password' => 'long-enough-1'])
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'CONFLICT', 'message' => 'This email address is already in use.']]);
    }

    public function test_update_with_a_new_password_ends_the_users_sessions_and_a_superadmin_keeps_their_own(): void
    {
        $admin = $this->superadmin();
        $target = User::factory()->create(['password' => Hash::make('old-password-1')]);
        DB::table('sessions')->insert(['id' => 'target-device', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($admin);

        $this->postJson('/_actions/users.update', [
            'id' => $target->id, 'name' => $target->name, 'email' => $target->email, 'role' => 'superadmin', 'password' => 'new-password-2',
        ])->assertOk()->assertExactJson(['data' => ['id' => $target->id]]);

        $this->assertTrue(Hash::check('new-password-2', $target->fresh()->password));
        $this->assertSame('superadmin', $target->fresh()->role);
        $this->assertNull($target->fresh()->department_id);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
    }

    public function test_update_without_a_password_keeps_it_but_a_new_email_ends_the_sessions(): void
    {
        $target = User::factory()->create(['password' => Hash::make('old-password-1')]);
        DB::table('sessions')->insert(['id' => 'target-device', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($this->superadmin());

        $this->postJson('/_actions/users.update', ['id' => $target->id, 'name' => 'Renamed', 'email' => $target->email, 'role' => 'superadmin'])->assertOk();
        $this->assertSame(1, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertTrue(Hash::check('old-password-1', $target->fresh()->password));

        $this->postJson('/_actions/users.update', ['id' => $target->id, 'name' => 'Renamed', 'email' => 'changed@example.org', 'role' => 'superadmin'])->assertOk();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
    }

    public function test_update_of_an_unknown_user_is_not_found(): void
    {
        $this->actingAs($this->superadmin());
        $this->postJson('/_actions/users.update', ['id' => 9999, 'name' => 'A', 'email' => 'a@example.org', 'role' => 'superadmin'])
            ->assertStatus(404);
    }

    public function test_the_last_superadmin_cannot_be_changed_to_a_department_user(): void
    {
        $department = $this->department();
        $only = $this->superadmin();
        $this->actingAs($only);

        $this->postJson('/_actions/users.update', [
            'id' => $only->id, 'name' => $only->name, 'email' => $only->email, 'role' => 'department_user', 'departmentId' => $department->id,
        ])->assertStatus(403)->assertJson(['error' => ['message' => 'The last superadmin cannot be changed to a department user.']]);
        $this->assertSame('superadmin', $only->fresh()->role);

        $second = $this->superadmin();
        $this->postJson('/_actions/users.update', [
            'id' => $second->id, 'name' => $second->name, 'email' => $second->email, 'role' => 'department_user', 'departmentId' => $department->id,
        ])->assertOk();
        $this->assertSame($department->id, $second->fresh()->department_id);
    }

    public function test_delete_removes_a_user_but_never_yourself_or_the_last_superadmin(): void
    {
        $admin = $this->superadmin();
        $member = User::factory()->create();
        $this->actingAs($admin);

        $this->postJson('/_actions/users.delete', ['id' => $admin->id])
            ->assertStatus(400)->assertJson(['error' => ['message' => 'Cannot delete the currently signed-in user.']]);
        $this->postJson('/_actions/users.delete', ['id' => $member->id])
            ->assertOk()->assertExactJson(['data' => ['id' => $member->id]]);
        $this->assertSame(0, User::query()->whereKey($member->id)->count());
        $this->postJson('/_actions/users.delete', ['id' => 9999])->assertStatus(404);
    }

    public function test_one_superadmin_may_delete_another_while_one_remains(): void
    {
        $admin = $this->superadmin();
        $other = $this->superadmin();
        $this->actingAs($admin);

        $this->postJson('/_actions/users.delete', ['id' => $other->id])->assertOk();
        $this->assertSame(1, User::query()->where('role', 'superadmin')->count());
    }
}
