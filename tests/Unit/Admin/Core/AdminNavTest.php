<?php

namespace Tests\Unit\Admin\Core;

use App\Admin\AdminNav;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class AdminNavTest extends TestCase
{
    private static function user(string $role): User
    {
        $user = new User;
        $user->role = $role;

        return $user;
    }

    /** @return list<string> */
    private static function active(array $items): array
    {
        return array_values(array_map(fn (array $item) => $item['href'], array_filter($items, fn (array $item) => $item['active'])));
    }

    public function test_the_most_specific_link_is_the_current_page(): void
    {
        $items = AdminNav::items(self::user(User::ROLE_SUPERADMIN), ['kind' => 'department', 'id' => 3], '/admin/departments/3/members');

        $this->assertSame(['/admin/departments/3/members'], self::active($items));
    }

    public function test_pages_below_a_link_keep_it_current_and_view_site_only_matches_the_home_page(): void
    {
        $user = self::user(User::ROLE_SUPERADMIN);

        $this->assertSame(['/admin/posts'], self::active(AdminNav::items($user, ['kind' => 'all'], '/admin/posts/12/edit')));
        $this->assertSame([], self::active(AdminNav::items($user, ['kind' => 'all'], '/admin/postsx')));
        $this->assertSame(['/dashboard'], self::active(AdminNav::items($user, ['kind' => 'all'], '/dashboard')));
    }

    public function test_department_users_do_not_see_superadmin_links(): void
    {
        $hrefs = array_column(AdminNav::items(self::user(User::ROLE_DEPARTMENT_USER), ['kind' => 'department', 'id' => 1], '/dashboard'), 'href');

        $this->assertContains('/admin/departments/1/members', $hrefs);
        $this->assertNotContains('/admin/users', $hrefs);
        $this->assertNotContains('/admin/navlinks', $hrefs);
    }
}
