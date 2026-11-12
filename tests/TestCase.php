<?php

namespace Tests;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /** Signs in a Superadmin (a new one unless given). */
    protected function actingAsSuperadmin(?User $user = null): User
    {
        $user ??= $this->makeUser(User::ROLE_SUPERADMIN);
        $this->actingAs($user);

        return $user;
    }

    /** Signs in a Department user of `$department` (a new Department and user unless given). */
    protected function actingAsDepartmentUser(?Department $department = null, ?User $user = null): User
    {
        $department ??= Department::query()->create(['name' => 'Dept '.Str::random(6), 'slug' => Str::lower(Str::random(8))]);
        $user ??= $this->makeUser(User::ROLE_DEPARTMENT_USER, $department);
        $this->actingAs($user);

        return $user;
    }

    /** Calls the action `$name` (`posts.create`) as the current session: JSON input, or multipart when it holds files. */
    protected function postAction(string $name, array $input = [], array $headers = []): TestResponse
    {
        $hasFile = collect($input)->contains(fn ($value) => $value instanceof \SplFileInfo);

        return $hasFile
            ? $this->post("/_actions/{$name}", $input, $headers + ['Accept' => 'application/json'])
            : $this->postJson("/_actions/{$name}", $input, $headers);
    }

    private function makeUser(string $role, ?Department $department = null): User
    {
        return User::query()->create([
            'name' => 'Test '.Str::random(6),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'role' => $role,
            'department_id' => $department?->id,
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
    }
}
