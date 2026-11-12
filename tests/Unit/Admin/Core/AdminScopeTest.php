<?php

namespace Tests\Unit\Admin\Core;

use App\Admin\AdminScope;
use App\Models\Department;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminScopeTest extends TestCase
{
    use RefreshDatabase;

    private const DEPARTMENTS = [
        ['id' => 10, 'name' => 'College of IT', 'slug' => 'cit'],
        ['id' => 11, 'name' => 'College of Business', 'slug' => 'cba'],
    ];

    private function user(string $role, ?int $departmentId): User
    {
        return (new User)->forceFill(['id' => 1, 'role' => $role, 'department_id' => $departmentId]);
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_SUPERADMIN, null);
    }

    private function cit(): User
    {
        return $this->user(User::ROLE_DEPARTMENT_USER, 10);
    }

    private function dept(int $id): array
    {
        return ['kind' => 'department'] + collect(self::DEPARTMENTS)->firstWhere('id', $id);
    }

    public function test_parse_scope(): void
    {
        $this->assertSame(['kind' => 'all'], AdminScope::parseScope('all'));
        $this->assertSame(['kind' => 'main'], AdminScope::parseScope('main'));
        $this->assertSame(['kind' => 'department', 'id' => 10], AdminScope::parseScope('10'));
        foreach ([null, '', 'nope', '0', '-3', '1.5', '10abc', '99999999999'] as $value) {
            $this->assertSame(['kind' => 'all'], AdminScope::parseScope($value), (string) $value);
        }
    }

    public function test_superadmins_choose_freely(): void
    {
        $admin = $this->admin();
        $this->assertSame(['kind' => 'all'], AdminScope::resolveScope($admin, ['kind' => 'all'], self::DEPARTMENTS));
        $this->assertSame(['kind' => 'main'], AdminScope::resolveScope($admin, ['kind' => 'main'], self::DEPARTMENTS));
        $this->assertEquals(
            ['kind' => 'department', 'id' => 11, 'name' => 'College of Business', 'slug' => 'cba'],
            AdminScope::resolveScope($admin, ['kind' => 'department', 'id' => 11], self::DEPARTMENTS),
        );
        $this->assertSame(['kind' => 'all'], AdminScope::resolveScope($admin, ['kind' => 'department', 'id' => 999], self::DEPARTMENTS));
        $this->assertSame(['kind' => 'all'], AdminScope::resolveScope($admin, ['kind' => 'department', 'id' => 10], []));
    }

    public function test_department_users_are_pinned_to_their_department(): void
    {
        $own = $this->dept(10);
        foreach ([['kind' => 'all'], ['kind' => 'main'], ['kind' => 'department', 'id' => 11]] as $parsed) {
            $this->assertEquals($own, AdminScope::resolveScope($this->cit(), $parsed, self::DEPARTMENTS));
        }
        $orphan = $this->user(User::ROLE_DEPARTMENT_USER, null);
        $this->assertSame(['kind' => 'all'], AdminScope::resolveScope($orphan, ['kind' => 'main'], self::DEPARTMENTS));
        $this->assertSame(['kind' => 'all'], AdminScope::resolveScope($this->cit(), ['kind' => 'all'], []));
    }

    /** Ids of the posts a scope constraint selects. @return list<int> */
    private function ids(\Closure $where): array
    {
        return Post::query()->where($where)->orderBy('id')->pluck('id')->all();
    }

    private function seedPosts(): void
    {
        foreach ([[10, 'cit'], [11, 'cba']] as [$id, $slug]) {
            Department::query()->forceCreate(['id' => $id, 'name' => strtoupper($slug), 'slug' => $slug]);
        }
        $owner = User::factory()->superadmin()->create();
        // post 1: CIT, 2: CBA, 3: general
        foreach ([1 => 10, 2 => 11, 3 => null] as $id => $departmentId) {
            Post::query()->forceCreate([
                'id' => $id, 'title' => "Post {$id}", 'slug' => "post-{$id}", 'content' => 'Body', 'type' => 'article',
                'department_id' => $departmentId, 'user_id' => $owner->id,
            ]);
        }
    }

    public function test_superadmin_department_where_follows_the_scope(): void
    {
        $this->seedPosts();
        $admin = $this->admin();
        $this->assertSame([1, 2, 3], $this->ids(AdminScope::departmentWhere($admin, ['kind' => 'all'])));
        $this->assertSame([3], $this->ids(AdminScope::departmentWhere($admin, ['kind' => 'main'])));
        $this->assertSame([1], $this->ids(AdminScope::departmentWhere($admin, $this->dept(10))));
        $this->assertSame([2, 3], $this->ids(AdminScope::departmentWhere($admin, $this->dept(11), ['includeGeneral' => true])));
        $this->assertSame([3], $this->ids(AdminScope::departmentWhere($admin, ['kind' => 'main'], ['includeGeneral' => true])));
        $this->assertSame([1, 2, 3], $this->ids(AdminScope::departmentWhere($admin, ['kind' => 'all'], ['includeGeneral' => true])));
    }

    public function test_no_scope_moves_a_department_user_out_of_their_department(): void
    {
        $this->seedPosts();
        foreach ([['kind' => 'all'], ['kind' => 'main'], $this->dept(10), $this->dept(11)] as $scope) {
            $this->assertSame([1], $this->ids(AdminScope::departmentWhere($this->cit(), $scope)));
            $this->assertSame([1, 3], $this->ids(AdminScope::departmentWhere($this->cit(), $scope, ['includeGeneral' => true])));
            $orphan = $this->user(User::ROLE_DEPARTMENT_USER, null);
            $this->assertSame([], $this->ids(AdminScope::departmentWhere($orphan, $scope)));
        }
    }

    public function test_a_search_or_cannot_widen_the_actor_restriction(): void
    {
        $this->seedPosts();
        $search = fn ($q) => $q->where(fn ($g) => $g->where('title', 'like', '%Post%')->orWhere('slug', 'like', '%post%'));
        foreach ([['kind' => 'all'], ['kind' => 'main'], $this->dept(11)] as $scope) {
            $this->assertSame([1], $this->ids(AdminScope::listWhere($this->cit(), $scope, [$search, '', null, false])));
            $this->assertSame([1, 3], $this->ids(AdminScope::listWhere($this->cit(), $scope, [$search], ['includeGeneral' => true])));
        }
        $this->assertSame([1, 2, 3], $this->ids(AdminScope::listWhere($this->admin(), ['kind' => 'all'], [false])));
    }

    public function test_labels_and_default_department(): void
    {
        $this->assertSame('All departments', AdminScope::scopeLabel(['kind' => 'all']));
        $this->assertSame('Main page', AdminScope::scopeLabel(['kind' => 'main']));
        $this->assertSame('College of IT', AdminScope::scopeLabel($this->dept(10)));
        $this->assertNull(AdminScope::scopeDefaultDepartment(['kind' => 'all']));
        $this->assertNull(AdminScope::scopeDefaultDepartment(['kind' => 'main']));
        $this->assertSame(10, AdminScope::scopeDefaultDepartment($this->dept(10)));
    }
}
