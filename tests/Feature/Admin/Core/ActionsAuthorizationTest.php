<?php

namespace Tests\Feature\Admin\Core;

use App\Models\Department;
use App\Models\Download;
use App\Models\DownloadDirectory;
use App\Models\HeroSection;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Authorization of the posts, heroSections, settings, departments, downloads and directories actions seen
 * from outside (`POST /_actions/<name>`): a rejected call must leave the target row as it was.
 */
class ActionsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cit;

    private int $cbaPost;

    private int $mainPost;

    private int $cbaHero;

    private int $cbaDownload;

    private int $cbaFolder;

    protected function setUp(): void
    {
        parent::setUp();
        Department::query()->forceCreate(['id' => 10, 'name' => 'CIT', 'slug' => 'cit']);
        Department::query()->forceCreate(['id' => 11, 'name' => 'CBA', 'slug' => 'cba']);
        $this->admin = User::factory()->superadmin()->create();
        $this->cit = User::factory()->forDepartment(10)->create();

        $post = fn (string $slug, ?int $departmentId) => Post::query()->forceCreate([
            'title' => "Post {$slug}", 'slug' => $slug, 'content' => 'Body', 'type' => 'article',
            'department_id' => $departmentId, 'user_id' => $this->admin->id,
        ])->id;
        $this->cbaPost = $post('cba-post', 11);
        $this->mainPost = $post('main-post', null);
        $this->cbaHero = HeroSection::query()->forceCreate(['title' => 'CBA hero', 'content' => 'Body', 'department_id' => 11])->id;
        $this->cbaDownload = Download::query()->forceCreate([
            'file_name' => 'CBA file', 'file_path' => 'downloads/cba.pdf', 'file_size' => 1, 'department_id' => 11, 'user_id' => $this->admin->id,
        ])->id;
        $this->cbaFolder = DownloadDirectory::query()->forceCreate(['name' => 'CBA folder', 'department_id' => 11])->id;
    }

    private function action(?User $user, string $name, array $input = []): TestResponse
    {
        return ($user ? $this->actingAs($user) : $this)->postJson("/_actions/{$name}", $input);
    }

    public function test_signed_out_calls_are_unauthorized(): void
    {
        foreach (['posts.create', 'posts.update', 'posts.delete', 'heroSections.update', 'settings.updateLanding', 'departments.create', 'downloads.delete', 'directories.create'] as $name) {
            $this->action(null, $name)->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHORIZED');
        }
    }

    public function test_a_department_user_cannot_change_posts_of_another_department_or_the_main_page(): void
    {
        $form = ['title' => 'Hijack', 'content' => 'Hijacked', 'type' => 'article', 'isFeatured' => false, 'hideAuthor' => false, 'isHidden' => false];
        foreach ([$this->cbaPost, $this->mainPost] as $id) {
            $before = Post::query()->find($id)->only(['title', 'content', 'department_id']);
            $this->action($this->cit, 'posts.update', ['id' => $id] + $form)->assertStatus(403);
            $this->action($this->cit, 'posts.delete', ['id' => $id])->assertStatus(403);
            $this->assertSame($before, Post::query()->find($id)->only(['title', 'content', 'department_id']));
        }
    }

    public function test_a_department_user_cannot_change_other_rows(): void
    {
        $this->action($this->cit, 'heroSections.update', ['id' => $this->cbaHero, 'title' => 'Hijack', 'content' => 'x', 'order' => 99, 'isActive' => false])->assertStatus(403);
        $this->action($this->cit, 'heroSections.delete', ['id' => $this->cbaHero])->assertStatus(403);
        $this->action($this->cit, 'downloads.delete', ['id' => $this->cbaDownload])->assertStatus(403);
        $this->action($this->cit, 'directories.delete', ['id' => $this->cbaFolder])->assertStatus(403);
        $this->assertSame('CBA hero', HeroSection::query()->find($this->cbaHero)->title);
        $this->assertTrue(Download::query()->whereKey($this->cbaDownload)->exists());
        $this->assertTrue(DownloadDirectory::query()->whereKey($this->cbaFolder)->exists());
    }

    public function test_a_missing_row_is_not_found(): void
    {
        $this->action($this->cit, 'posts.delete', ['id' => 9999])->assertStatus(404);
        $this->action($this->admin, 'downloads.delete', ['id' => 9999])->assertStatus(404);
    }

    public function test_superadmin_only_actions_are_forbidden_for_department_users(): void
    {
        $this->action($this->cit, 'settings.updateLanding', ['welcomeHeroContent' => 'Hijack'])->assertStatus(403);
        $this->action($this->cit, 'departments.create', ['name' => 'Hijack'])->assertStatus(403);
        $this->action($this->cit, 'departments.delete', ['id' => 11])->assertStatus(403);
        $this->assertTrue(Department::query()->whereKey(11)->exists());
        $this->assertFalse(Department::query()->where('name', 'Hijack')->exists());
    }

    public function test_a_department_user_cannot_update_another_department(): void
    {
        $this->action($this->cit, 'departments.update', ['id' => 11, 'name' => 'Hijack'])->assertStatus(403);
        $this->assertSame('CBA', Department::query()->find(11)->name);
    }

    public function test_a_department_user_may_update_their_own_department(): void
    {
        $this->action($this->cit, 'departments.update', ['id' => 10, 'name' => 'College of IT'])
            ->assertOk()->assertJsonPath('data.id', 10);
        $this->assertSame('College of IT', Department::query()->find(10)->name);
    }

    public function test_creates_name_another_department_but_land_in_the_users_own(): void
    {
        $this->action($this->cit, 'directories.create', ['name' => 'Forced folder', 'departmentId' => 11])->assertOk();
        $this->assertSame(10, DownloadDirectory::query()->where('name', 'Forced folder')->value('department_id'));

        $this->action($this->cit, 'heroSections.create', ['title' => 'Forced hero', 'content' => 'Body', 'order' => 1, 'isActive' => true, 'departmentId' => 11])->assertOk();
        $this->assertSame(10, HeroSection::query()->where('title', 'Forced hero')->value('department_id'));

        $this->actingAs($this->cit)->post('/_actions/posts.create', [
            'title' => 'Forced post', 'content' => 'Body', 'type' => 'article', 'isFeatured' => 'false', 'hideAuthor' => 'false', 'departmentId' => '11',
        ], ['Accept' => 'application/json'])->assertOk();
        $post = Post::query()->where('title', 'Forced post')->first();
        $this->assertSame(10, $post->department_id);
        $this->assertSame($this->cit->id, $post->user_id);
    }

    public function test_superadmin_picks_the_department_and_it_must_exist(): void
    {
        $this->action($this->admin, 'directories.create', ['name' => 'CBA folder 2', 'departmentId' => 11])->assertOk();
        $this->assertSame(11, DownloadDirectory::query()->where('name', 'CBA folder 2')->value('department_id'));
        $this->action($this->admin, 'directories.create', ['name' => 'Ghost', 'departmentId' => 999])
            ->assertStatus(400)->assertJsonPath('error.message', 'The selected department does not exist.');
    }

    public function test_input_errors_use_the_original_messages(): void
    {
        $this->action($this->admin, 'directories.create', ['name' => ''])
            ->assertStatus(400)->assertJsonPath('error.type', 'ActionInputError')
            ->assertJsonPath('error.fields.name.0', 'The folder name is required.');
        $this->action($this->admin, 'heroSections.create', ['content' => 'Body', 'order' => 1.5, 'isActive' => true])
            ->assertStatus(400)->assertJsonPath('error.fields.order.0', 'The order must be a whole number.');
        $this->action($this->admin, 'posts.create', ['title' => '', 'content' => 'Body', 'type' => 'nope', 'isFeatured' => false, 'hideAuthor' => false, 'isHidden' => false])
            ->assertStatus(400)
            ->assertJsonPath('error.fields.title.0', 'The title is required.')
            ->assertJsonPath('error.fields.type.0', 'Choose a valid post type.');
        $this->action($this->admin, 'posts.create', ['title' => 'No body', 'content' => '', 'type' => 'article', 'isFeatured' => false, 'hideAuthor' => false, 'isHidden' => false])
            ->assertStatus(400)->assertJsonPath('error.fields.content.0', 'The content is required.');
        $this->action($this->admin, 'posts.create', [
            'title' => 'Event', 'content' => 'Body', 'type' => 'event', 'isFeatured' => false, 'hideAuthor' => false, 'isHidden' => false,
            'startDate' => '2026-05-02T10:00:00Z', 'endDate' => '2026-05-01T10:00:00Z',
        ])->assertStatus(400)->assertJsonPath('error.fields.endDate.0', 'The end date must be on or after the start date.');
    }

    public function test_post_slugs_are_unique_and_a_department_name_conflicts(): void
    {
        $form = ['title' => 'Same Title', 'content' => 'Body', 'type' => 'article', 'isFeatured' => false, 'hideAuthor' => false, 'isHidden' => false];
        $this->action($this->admin, 'posts.create', $form)->assertOk();
        $this->action($this->admin, 'posts.create', $form)->assertOk();
        $this->assertEqualsCanonicalizing(['same-title', 'same-title-1'], Post::query()->where('title', 'Same Title')->pluck('slug')->all());

        $this->action($this->admin, 'departments.create', ['name' => 'CBA'])
            ->assertStatus(409)->assertJsonPath('error.message', 'A department with this name already exists.');
    }

    public function test_superadmin_can_manage_any_row(): void
    {
        $this->action($this->admin, 'posts.delete', ['id' => $this->cbaPost])->assertOk()->assertJsonPath('data.id', $this->cbaPost);
        $this->assertFalse(Post::query()->whereKey($this->cbaPost)->exists());
        $this->action($this->admin, 'heroSections.update', ['id' => $this->cbaHero, 'title' => 'Renamed', 'content' => 'Body', 'order' => 3, 'isActive' => true, 'departmentId' => null])->assertOk();
        $hero = HeroSection::query()->find($this->cbaHero);
        $this->assertSame(['Renamed', null, 3], [$hero->title, $hero->department_id, $hero->order]);
        $this->action($this->admin, 'settings.updateLanding', ['welcomeHeroContent' => 'Welcome'])->assertOk()->assertJsonPath('data.saved', true);
    }

    public function test_a_post_stores_its_highlight_image_and_replaces_it(): void
    {
        $form = ['title' => 'With image', 'content' => 'Body', 'type' => 'article', 'isFeatured' => 'false', 'hideAuthor' => 'false'];
        $this->actingAs($this->admin)->post('/_actions/posts.create', $form + [
            'highlightImage' => UploadedFile::fake()->image('a.png', 40, 30),
        ], ['Accept' => 'application/json'])->assertOk();
        $post = Post::query()->where('title', 'With image')->first();
        $this->assertNotNull($post->highlight_image_path);
        $first = $post->highlight_image_path;

        $this->actingAs($this->admin)->post('/_actions/posts.update', ['id' => (string) $post->id] + $form + [
            'highlightImage' => UploadedFile::fake()->image('b.png', 40, 30),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertNotSame($first, $post->fresh()->highlight_image_path);

        $this->actingAs($this->admin)->post('/_actions/posts.update', ['id' => (string) $post->id] + $form + [
            'highlightImage' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(400)->assertJsonStructure(['error' => ['fields' => ['highlightImage']]]);
    }

    public function test_an_emptied_department_description_and_hero_content_are_stored_as_empty_strings(): void
    {
        Department::query()->whereKey(10)->update(['description' => 'Old', 'hero_content' => 'Old hero']);
        $this->actingAs($this->admin)->post('/_actions/departments.update', ['id' => '10', 'name' => 'CIT', 'description' => '', 'heroContent' => ''], ['Accept' => 'application/json'])->assertOk();
        $department = Department::query()->find(10);
        $this->assertSame('', $department->description);
        $this->assertSame('', $department->hero_content);
    }

    public function test_a_slug_of_zero_is_kept_like_in_js(): void
    {
        $this->action($this->admin, 'posts.create', ['title' => 'Zero', 'slug' => '0', 'content' => 'Body', 'type' => 'article', 'isFeatured' => false, 'hideAuthor' => false, 'isHidden' => false])->assertOk();
        $this->assertTrue(Post::query()->where('slug', '0')->exists());
        $this->action($this->admin, 'departments.create', ['name' => '0'])->assertOk();
        $this->assertTrue(Department::query()->where('slug', '0')->exists());
    }

    public function test_malformed_values_fail_with_zod_wording(): void
    {
        $form = ['title' => 'T', 'content' => 'Body', 'type' => 'article'];
        // JSON is strict; a form reads any submitted value other than "false" as a checked box (Astro form actions).
        $this->action($this->admin, 'posts.create', $form + ['isFeatured' => 'yes', 'hideAuthor' => false, 'isHidden' => false])
            ->assertStatus(400)->assertJsonPath('error.fields.isFeatured.0', 'Invalid input: expected boolean, received string');
        $this->actingAs($this->admin)->post('/_actions/posts.create', $form + ['isFeatured' => 'yes', 'hideAuthor' => 'false'], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertTrue(Post::query()->where('title', 'T')->value('is_featured'));
        $this->action($this->admin, 'posts.create', ['title' => str_repeat('a', 256), 'content' => 'Body', 'type' => 'article', 'isFeatured' => false, 'hideAuthor' => false, 'isHidden' => false])
            ->assertStatus(400)->assertJsonPath('error.fields.title.0', 'Too big: expected string to have <=255 characters');
        $this->action($this->admin, 'posts.create', $form + ['hideAuthor' => false, 'isHidden' => false])
            ->assertStatus(400)->assertJsonPath('error.fields.isFeatured.0', 'Invalid input: expected boolean, received undefined');
    }
}
