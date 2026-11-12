<?php

namespace Tests\Feature\Admin\Core;

use App\Models\Department;
use App\Models\Download;
use App\Models\HeroSection;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The admin pages of this area render for the right people, with the scope applied. */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Department::query()->forceCreate(['id' => 10, 'name' => 'College of IT', 'slug' => 'cit']);
        Department::query()->forceCreate(['id' => 11, 'name' => 'College of Business', 'slug' => 'cba']);
        $this->admin = User::factory()->superadmin()->create();
        $this->cit = User::factory()->forDepartment(10)->create();
        foreach ([['CIT post', 10], ['CBA post', 11], ['Main post', null]] as $i => [$title, $departmentId]) {
            Post::query()->forceCreate([
                'title' => $title, 'slug' => "p-{$i}", 'content' => 'Body', 'type' => 'article',
                'department_id' => $departmentId, 'user_id' => $this->admin->id,
            ]);
        }
        HeroSection::query()->forceCreate(['title' => 'CBA hero', 'content' => 'Body', 'department_id' => 11]);
        Download::query()->forceCreate(['file_name' => 'CBA file', 'file_path' => 'downloads/a.pdf', 'file_size' => 1, 'department_id' => 11, 'user_id' => $this->admin->id]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect();
        $this->get('/admin/posts')->assertRedirect();
    }

    public function test_the_lists_render_and_follow_the_admin_scope_cookie(): void
    {
        $this->actingAs($this->admin);
        $all = $this->get('/admin/posts')->assertOk();
        $all->assertSee('CIT post')->assertSee('CBA post')->assertSee('Main post');

        $cba = $this->withHeader('Cookie', 'admin_scope=11')->get('/admin/posts')->assertOk();
        $cba->assertSee('CBA post')->assertDontSee('CIT post')->assertDontSee('Main post');

        $main = $this->withHeader('Cookie', 'admin_scope=main')->get('/admin/posts')->assertOk();
        $main->assertSee('Main post')->assertDontSee('CBA post');

        // An unknown department id falls back to every department.
        $this->withHeader('Cookie', 'admin_scope=999')->get('/admin/posts')->assertOk()->assertSee('CIT post');
    }

    public function test_a_department_user_is_pinned_whatever_the_cookie_says(): void
    {
        $this->actingAs($this->cit);
        foreach (['all', 'main', '11'] as $scope) {
            $this->withHeader('Cookie', "admin_scope={$scope}")->get('/admin/posts')->assertOk()
                ->assertSee('CIT post')->assertDontSee('CBA post')->assertDontSee('Main post');
        }
        $this->withHeader('Cookie', 'admin_scope=11')->get('/dashboard')->assertOk()->assertSee('Overview · College of IT');
    }

    public function test_search_cannot_reach_another_departments_rows(): void
    {
        $this->actingAs($this->cit)->get('/admin/posts?q=post')->assertOk()
            ->assertSee('CIT post')->assertDontSee('CBA post');
    }

    public function test_edit_pages_check_the_row_department(): void
    {
        $cbaPost = Post::query()->where('title', 'CBA post')->value('id');
        $citPost = Post::query()->where('title', 'CIT post')->value('id');
        $this->actingAs($this->cit);
        $this->get("/admin/posts/{$cbaPost}/edit")->assertForbidden();
        $this->get('/admin/posts/9999/edit')->assertNotFound();
        $this->get('/admin/posts/abc/edit')->assertNotFound();
        $this->get("/admin/posts/{$citPost}/edit")->assertOk();
        $this->get('/admin/departments/11/edit')->assertForbidden();
        $this->get('/admin/departments/10/edit')->assertOk();
    }

    public function test_superadmin_only_pages(): void
    {
        $this->actingAs($this->cit);
        foreach (['/admin/users', '/admin/users/create', '/admin/departments/create', '/admin/settings/landing'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->actingAs($this->admin);
        foreach (['/admin/users', '/admin/users/create', "/admin/users/{$this->cit->id}/edit", '/admin/departments', '/admin/departments/create', '/admin/settings/landing', '/admin/hero-sections', '/admin/hero-sections/create', '/admin/posts/create', '/admin/downloads', '/admin/downloads/create', '/dashboard'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
