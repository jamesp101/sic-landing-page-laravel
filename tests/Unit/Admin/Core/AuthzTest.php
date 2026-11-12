<?php

namespace Tests\Unit\Admin\Core;

use App\Models\Department;
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

    private function row(?int $departmentId): object
    {
        return (object) ['department_id' => $departmentId];
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

    public function test_scope_department(): void
    {
        $admin = $this->user(1, User::ROLE_SUPERADMIN, null);
        $cit = $this->user(2, User::ROLE_DEPARTMENT_USER, 10);
        $orphan = $this->user(3, User::ROLE_DEPARTMENT_USER, null);

        $other = $this->row(99);
        $this->assertSame($other, Authz::scopeDepartment($admin, $other));
        $general = $this->row(null);
        $this->assertSame($general, Authz::scopeDepartment($admin, $general));

        $own = $this->row(10);
        $this->assertSame($own, Authz::scopeDepartment($cit, $own));
        $this->assertSame('FORBIDDEN', $this->code(fn () => Authz::scopeDepartment($cit, $this->row(11))));
        $this->assertSame('FORBIDDEN', $this->code(fn () => Authz::scopeDepartment($cit, $this->row(null))));
        $this->assertSame('FORBIDDEN', $this->code(fn () => Authz::scopeDepartment($orphan, $this->row(null))));
        // A missing row is NOT_FOUND before ownership is checked.
        $this->assertSame('NOT_FOUND', $this->code(fn () => Authz::scopeDepartment($cit, null)));
    }

    public function test_scope_own_department(): void
    {
        $admin = $this->user(1, User::ROLE_SUPERADMIN, null);
        $cit = $this->user(2, User::ROLE_DEPARTMENT_USER, 10);
        $department = fn (int $id) => (new Department)->forceFill(['id' => $id]);

        $this->assertSame(10, Authz::scopeOwnDepartment($cit, $department(10))->id);
        $this->assertSame('FORBIDDEN', $this->code(fn () => Authz::scopeOwnDepartment($cit, $department(11))));
        $this->assertSame(11, Authz::scopeOwnDepartment($admin, $department(11))->id);
        $this->assertSame('NOT_FOUND', $this->code(fn () => Authz::scopeOwnDepartment($admin, null)));
    }

    public function test_department_for_write(): void
    {
        $admin = $this->user(1, User::ROLE_SUPERADMIN, null);
        $cit = $this->user(2, User::ROLE_DEPARTMENT_USER, 10);
        $orphan = $this->user(3, User::ROLE_DEPARTMENT_USER, null);

        $this->assertSame(10, Authz::departmentForWrite($cit, 11));
        $this->assertSame(10, Authz::departmentForWrite($cit, null));
        $this->assertSame('FORBIDDEN', $this->code(fn () => Authz::departmentForWrite($orphan, 10)));
        $this->assertSame(11, Authz::departmentForWrite($admin, 11));
        $this->assertNull(Authz::departmentForWrite($admin, null));
    }
}
