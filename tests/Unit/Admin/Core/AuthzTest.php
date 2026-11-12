<?php

namespace Tests\Unit\Admin\Core;

use App\Models\User;
use App\Support\Authz;
use App\Support\RequestError;
use PHPUnit\Framework\TestCase;

class AuthzTest extends TestCase
{
    private function user(int $id, string $role, ?int $departmentId): User
    {
        return (new User)->forceFill(['id' => $id, 'role' => $role, 'department_id' => $departmentId]);
    }

    private function code(callable $fn): ?string
    {
        try {
            $fn();
        } catch (RequestError $error) {
            return $error->errorCode;
        }

        return null;
    }

    public function test_guests_are_unauthorized(): void
    {
        $this->assertSame('UNAUTHORIZED', $this->code(fn () => Authz::requireUser(null)));
        $this->assertSame('UNAUTHORIZED', $this->code(fn () => Authz::requireSuperAdmin(null)));
    }

    public function test_department_users_are_forbidden_from_superadmin_actions(): void
    {
        $admin = $this->user(1, User::ROLE_SUPERADMIN, null);
        $this->assertSame('FORBIDDEN', $this->code(fn () => Authz::requireSuperAdmin($this->user(2, User::ROLE_DEPARTMENT_USER, 10))));
        $this->assertSame($admin, Authz::requireSuperAdmin($admin));
    }
}
