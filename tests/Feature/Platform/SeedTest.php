<?php

namespace Tests\Feature\Platform;

use App\Content\Content;
use App\Models\Department;
use App\Models\HeroSection;
use App\Models\Member;
use App\Models\NavigationLink;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Support\Authz;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoData;
use Database\Seeders\SchoolsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_runs_on_an_empty_database(): void
    {
        $this->artisan('sic:seed')->assertSuccessful();

        $this->assertSame(count(DemoData::USERS), User::count());
        $this->assertSame(count(DemoData::DEPARTMENTS), Department::count());
        $this->assertSame(count(DemoData::POSTS), Post::count());
        $this->assertSame(count(DemoData::MEMBERS), Member::count());
        $this->assertSame(count(DemoData::HERO_SECTIONS), HeroSection::count());
        $this->assertSame(2 + 1 + count(DemoData::DOWNLOAD_NAV_CHILDREN) + 1, NavigationLink::count());
        $this->assertSame(DemoData::WELCOME_HERO, Setting::where('key', 'welcome_hero_content')->value('value'));
    }

    public function test_users_get_their_role_department_and_a_working_password(): void
    {
        $this->artisan('sic:seed')->assertSuccessful();

        $admin = User::where('email', 'admin@sic.edu.ph')->firstOrFail();
        $this->assertTrue(Authz::isSuperAdmin($admin));
        $this->assertNull($admin->department_id);
        $this->assertTrue(Hash::check(config('sic.seed.superadmin_password'), $admin->password));

        $cit = User::where('email', 'cit@sic.edu.ph')->firstOrFail();
        $this->assertSame(User::ROLE_DEPARTMENT_USER, $cit->role);
        $this->assertSame(Department::where('slug', 'cit')->value('id'), $cit->department_id);
        $this->assertTrue(Hash::check(config('sic.seed.department_password'), $cit->password));
        $this->assertTrue($cit->email_verified);
    }

    public function test_rows_are_resolved_by_key_and_search_text_is_filled(): void
    {
        $this->artisan('sic:seed')->assertSuccessful();

        $post = Post::where('title', DemoData::POSTS['aiLab']['title'])->firstOrFail();
        $this->assertSame(User::where('email', 'cit@sic.edu.ph')->value('id'), $post->user_id);
        $this->assertSame(Department::where('slug', 'cit')->value('id'), $post->department_id);
        $this->assertSame(Content::searchTextOf($post->content), $post->search_text);
        $this->assertStringContainsString(DemoData::AI_LAB_HEADING, $post->search_text);

        $event = Post::where('title', DemoData::POSTS['foundationWeek']['title'])->firstOrFail();
        $this->assertSame('2026-08-15 00:00:00', $event->start_date->utc()->format('Y-m-d H:i:s'));

        $instructor = Member::where('name', 'Ada Lovelace')->firstOrFail();
        $this->assertSame('Dr. Alan Turing', Member::find($instructor->parent_id)->name);
        $this->assertSame(Department::where('slug', 'cit')->value('id'), $instructor->department_id);

        $downloads = NavigationLink::where('label', 'Downloads')->firstOrFail();
        $this->assertSame(
            DemoData::DOWNLOAD_NAV_CHILDREN,
            NavigationLink::where('parent_id', $downloads->id)->orderBy('order')->pluck('label')->all(),
        );
    }

    public function test_if_empty_is_idempotent(): void
    {
        $this->artisan('sic:seed --if-empty')->assertSuccessful();
        $counts = [User::count(), Post::count(), Member::count(), NavigationLink::count()];

        $this->artisan('sic:seed --if-empty')->expectsOutputToContain('already has users')->assertSuccessful();

        $this->assertSame($counts, [User::count(), Post::count(), Member::count(), NavigationLink::count()]);
    }

    public function test_if_empty_does_nothing_when_any_user_exists(): void
    {
        $this->actingAsSuperadmin();

        $this->artisan('sic:seed --if-empty')->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertSame(0, Post::count());
    }

    public function test_a_remote_database_is_refused(): void
    {
        // The live connection is already open; only the config the seeder reads changes.
        config(['database.connections.'.config('database.default').'.driver' => 'mysql', 'database.connections.'.config('database.default').'.host' => 'db.example.org']);

        $this->expectExceptionMessage('Refusing to seed the database on db.example.org');
        (new DatabaseSeeder)->run();
    }

    public function test_schools_seeder_adds_five_schools_once(): void
    {
        $this->artisan('sic:seed')->assertSuccessful();
        $this->seed(SchoolsSeeder::class);

        $this->assertSame(count(DemoData::DEPARTMENTS) + 5, Department::count());
        $sit = Department::where('slug', 'sit')->firstOrFail();
        $this->assertSame(20, Post::where('department_id', $sit->id)->count());
        $this->assertSame(1, Post::where('department_id', $sit->id)->where('is_featured', true)->count());
        $this->assertSame(1, Member::where('department_id', $sit->id)->whereNull('parent_id')->count());

        $postCount = Post::count();
        $this->seed(SchoolsSeeder::class);
        $this->assertSame($postCount, Post::count());
    }

    public function test_backfill_recomputes_search_text_and_keeps_updated_at(): void
    {
        $this->artisan('sic:seed')->assertSuccessful();
        $post = Post::firstOrFail();
        $updatedAt = '2020-01-02 03:04:05';
        DB::table('posts')->where('id', $post->id)->update(['search_text' => 'stale', 'updated_at' => $updatedAt]);
        DB::table('hero_sections')->update(['search_text' => '']);

        $this->artisan('sic:backfill-search')->expectsOutputToContain('posts: 1 of '.Post::count().' updated.')->assertSuccessful();

        $fresh = DB::table('posts')->where('id', $post->id)->first();
        $this->assertSame(Content::searchTextOf($post->content), $fresh->search_text);
        $this->assertSame($updatedAt, $fresh->updated_at);
        $this->assertNotSame('', HeroSection::firstOrFail()->search_text);

        $this->artisan('sic:backfill-search')->expectsOutputToContain('posts: 0 of')->assertSuccessful();
    }
}
