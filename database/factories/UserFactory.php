<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified' => true,
            'email_verified_at' => now(),
            'role' => User::ROLE_DEPARTMENT_USER,
            'department_id' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (User $user) {
            $user->password ??= static::$password ??= Hash::make('password');
        });
    }

    public function superadmin(): static
    {
        return $this->state(['role' => User::ROLE_SUPERADMIN, 'department_id' => null]);
    }

    public function forDepartment(int $departmentId): static
    {
        return $this->state(['role' => User::ROLE_DEPARTMENT_USER, 'department_id' => $departmentId]);
    }
}
