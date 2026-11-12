<?php

namespace Tests\Unit\Admin\Core;

use App\Admin\DepartmentAccess;
use App\Models\CarouselItem;
use App\Models\Department;
use App\Models\Member;
use App\Models\Post;
use App\Models\User;
use App\Support\RequestError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cit;

    private User $cba;

    private User $unassigned;

    protected function setUp(): void
    {
        parent::setUp();
        Department::query()->forceCreate(['id' => 10, 'name' => 'CIT', 'slug' => 'cit']);
        Department::query()->forceCreate(['id' => 11, 'name' => 'CBA', 'slug' => 'cba']);
        $this->admin = User::factory()->superadmin()->create();
        $this->cit = User::factory()->forDepartment(10)->create();
        $this->cba = User::factory()->forDepartment(11)->create();
        $this->unassigned = User::factory()->create();

        foreach ([1 => 10, 2 => 11, 3 => null] as $id => $departmentId) {
            Post::query()->forceCreate([
                'id' => $id, 'title' => "Post {$id}", 'slug' => "post-{$id}", 'content' => 'Body', 'type' => 'article',
                'department_id' => $departmentId, 'user_id' => $this->admin->id,
            ]);
        }
        foreach ([1 => 10, 2 => 10, 3 => 11] as $id => $departmentId) {
            CarouselItem::query()->forceCreate(['id' => $id, 'department_id' => $departmentId, 'image_path' => "carousels/{$id}.webp"]);
        }
        foreach ([1 => 10, 2 => 11] as $id => $departmentId) {
            Member::query()->forceCreate(['id' => $id, 'department_id' => $departmentId, 'name' => "Member {$id}", 'role' => 'Staff']);
        }
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

    public function test_superadmin_chooses_an_existing_department_or_none(): void
    {
        $this->assertSame(10, DepartmentAccess::resolveWriteDepartment($this->admin, 10));
        $this->assertNull(DepartmentAccess::resolveWriteDepartment($this->admin, null));
    }

    public function test_a_missing_department_is_a_bad_request(): void
    {
        $this->assertSame('BAD_REQUEST', $this->code(fn () => DepartmentAccess::resolveWriteDepartment($this->admin, 99)));
        $this->assertSame('BAD_REQUEST', $this->code(fn () => DepartmentAccess::assertDepartmentExists(99)));
    }

    public function test_department_users_are_forced_into_their_department(): void
    {
        $this->assertSame(10, DepartmentAccess::resolveWriteDepartment($this->cit, 11));
        $this->assertSame(10, DepartmentAccess::resolveWriteDepartment($this->cit, null));
        $this->assertSame('FORBIDDEN', $this->code(fn () => DepartmentAccess::resolveWriteDepartment($this->unassigned, 10)));
    }

    public function test_load_scoped(): void
    {
        $this->assertSame(2, DepartmentAccess::loadScoped($this->admin, 'post', 2)->id);
        $this->assertSame(1, DepartmentAccess::loadScoped($this->cit, 'post', 1)->id);
        $this->assertSame('NOT_FOUND', $this->code(fn () => DepartmentAccess::loadScoped($this->cit, 'post', 99)));
        $this->assertSame('NOT_FOUND', $this->code(fn () => DepartmentAccess::loadScoped($this->cit, 'post', null)));
        $this->assertSame('FORBIDDEN', $this->code(fn () => DepartmentAccess::loadScoped($this->cit, 'post', 2)));
        $this->assertSame('FORBIDDEN', $this->code(fn () => DepartmentAccess::loadScoped($this->cit, 'post', 3)));
    }

    public function test_load_own_department(): void
    {
        $this->assertSame(10, DepartmentAccess::loadOwnDepartment($this->admin, 10)->id);
        $this->assertSame(10, DepartmentAccess::loadOwnDepartment($this->cit, 10)->id);
        $this->assertSame('FORBIDDEN', $this->code(fn () => DepartmentAccess::loadOwnDepartment($this->cba, 10)));
        $this->assertSame('NOT_FOUND', $this->code(fn () => DepartmentAccess::loadOwnDepartment($this->admin, 99)));
    }

    public function test_load_scoped_many_keeps_the_order_of_the_ids(): void
    {
        $ids = fn (array $rows) => array_map(fn ($row) => $row->id, $rows);
        $this->assertSame([2, 1], $ids(DepartmentAccess::loadScopedMany($this->cit, 'carouselItem', [2, 1])));
        $this->assertSame([3, 1, 2], $ids(DepartmentAccess::loadScopedMany($this->admin, 'carouselItem', [3, 1, 2])));
        $this->assertSame('NOT_FOUND', $this->code(fn () => DepartmentAccess::loadScopedMany($this->cit, 'carouselItem', [1, 99])));
        $this->assertSame('FORBIDDEN', $this->code(fn () => DepartmentAccess::loadScopedMany($this->cit, 'carouselItem', [1, 3])));
        $this->assertSame('FORBIDDEN', $this->code(fn () => DepartmentAccess::loadScopedMany($this->unassigned, 'carouselItem', [1])));
    }

    public function test_load_member(): void
    {
        $this->assertSame(1, DepartmentAccess::loadMember($this->cit, 10, 1)->id);
        $this->assertSame(2, DepartmentAccess::loadMember($this->admin, 11, 2)->id);
        $this->assertSame('FORBIDDEN', $this->code(fn () => DepartmentAccess::loadMember($this->cba, 10, 1)));
        $this->assertSame('NOT_FOUND', $this->code(fn () => DepartmentAccess::loadMember($this->cit, 10, 99)));
        // A member of another department is not found through this one.
        $this->assertSame('NOT_FOUND', $this->code(fn () => DepartmentAccess::loadMember($this->cit, 10, 2)));
        $this->assertSame('NOT_FOUND', $this->code(fn () => DepartmentAccess::loadMember($this->admin, 99, 1)));
    }

    public function test_department_options(): void
    {
        $this->assertSame(['CBA', 'CIT'], array_column(DepartmentAccess::departmentOptions($this->admin), 'name'));
        $this->assertSame([['id' => 10, 'name' => 'CIT']], DepartmentAccess::departmentOptions($this->cit));
        $this->assertSame([], DepartmentAccess::departmentOptions($this->unassigned));
    }
}
