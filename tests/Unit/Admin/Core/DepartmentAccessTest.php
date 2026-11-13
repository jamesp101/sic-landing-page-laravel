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
use Illuminate\Http\Request;
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
        $this->assertSame(2, DepartmentAccess::loadScoped($this->admin, Post::class, 2)->id);
        $this->assertSame(1, DepartmentAccess::loadScoped($this->cit, Post::class, 1)->id);
        $this->assertSame('NOT_FOUND', $this->code(fn () => DepartmentAccess::loadScoped($this->cit, Post::class, 99)));
        $this->assertSame('NOT_FOUND', $this->code(fn () => DepartmentAccess::loadScoped($this->cit, Post::class, null)));
        $this->assertSame('FORBIDDEN', $this->code(fn () => DepartmentAccess::loadScoped($this->cit, Post::class, 2)));
        $this->assertSame('FORBIDDEN', $this->code(fn () => DepartmentAccess::loadScoped($this->cit, Post::class, 3)));
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
        $this->assertSame([2, 1], $ids(DepartmentAccess::loadScopedMany($this->cit, CarouselItem::class, [2, 1])));
        $this->assertSame([3, 1, 2], $ids(DepartmentAccess::loadScopedMany($this->admin, CarouselItem::class, [3, 1, 2])));
        $this->assertSame('NOT_FOUND', $this->code(fn () => DepartmentAccess::loadScopedMany($this->cit, CarouselItem::class, [1, 99])));
        $this->assertSame('FORBIDDEN', $this->code(fn () => DepartmentAccess::loadScopedMany($this->cit, CarouselItem::class, [1, 3])));
        $this->assertSame('FORBIDDEN', $this->code(fn () => DepartmentAccess::loadScopedMany($this->unassigned, CarouselItem::class, [1])));
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

    public function test_restriction_is_unset_for_superadmin_and_the_own_department_otherwise(): void
    {
        $this->assertNull(DepartmentAccess::restrictedDepartmentId($this->admin));
        $this->assertSame(10, DepartmentAccess::restrictedDepartmentId($this->cit));
        $this->assertSame([10], DepartmentAccess::visibleDepartments($this->cit)->pluck('id')->all());
        $this->assertSame([], DepartmentAccess::visibleDepartments($this->unassigned)->pluck('id')->all());
        $this->assertSame('FORBIDDEN', $this->code(fn () => DepartmentAccess::loadOwnDepartment($this->unassigned, 10)));
    }

    public function test_the_department_field_of_an_existing_row(): void
    {
        $cit = [['id' => 10, 'name' => 'CIT']];
        $all = [['id' => 11, 'name' => 'CBA'], ['id' => 10, 'name' => 'CIT']];

        // A Superadmin chooses: the row's Department, or the Main site (null).
        $this->assertSame(['options' => $all, 'value' => 11, 'locked' => false, 'allowMain' => true], DepartmentAccess::departmentField($this->admin, 11));
        $this->assertNull(DepartmentAccess::departmentField($this->admin, null)['value']);

        // A Department user is fixed to their own Department and cannot pick the Main site.
        $this->assertSame(['options' => $cit, 'value' => 10, 'locked' => true, 'allowMain' => false], DepartmentAccess::departmentField($this->cit, 10));

        // Without a Department there is nothing to select.
        $this->assertSame(['options' => [], 'value' => null, 'locked' => true, 'allowMain' => false], DepartmentAccess::departmentField($this->unassigned, null));
    }

    public function test_the_department_field_of_a_create_form_follows_the_admin_scope(): void
    {
        $field = fn (User $actor, ?string $scopeCookie) => DepartmentAccess::createDepartmentField($actor, $this->requestAs($actor, $scopeCookie));

        // A Superadmin starts on the scoped Department, else on the Main site.
        $scoped = $field($this->admin, '11');
        $this->assertSame([11, false, true], [$scoped['value'], $scoped['locked'], $scoped['allowMain']]);
        $this->assertNull($field($this->admin, null)['value']);
        $this->assertNull($field($this->admin, 'main')['value']);
        $this->assertNull($field($this->admin, 'all')['value']);

        // A Department user starts on their own Department whatever the cookie says.
        $own = $field($this->cit, null);
        $this->assertSame([10, true, false], [$own['value'], $own['locked'], $own['allowMain']]);
        $this->assertSame(10, $field($this->cit, '11')['value']);
        $this->assertSame([['id' => 10, 'name' => 'CIT']], $field($this->cit, '11')['options']);
    }

    private function requestAs(User $actor, ?string $scopeCookie): Request
    {
        $request = Request::create('/');
        if ($scopeCookie !== null) {
            $request->headers->set('Cookie', "admin_scope={$scopeCookie}");
        }
        $request->setUserResolver(fn () => $actor);

        return $request;
    }
}
