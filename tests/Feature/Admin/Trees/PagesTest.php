<?php

namespace Tests\Feature\Admin\Trees;

use App\Models\CarouselItem;
use App\Models\Department;
use App\Models\Member;
use App\Models\NavigationLink;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Unit\Carousel\StoresFiles;

/** The carousel designer, navigation links and org chart pages: access, scope and the island props. */
class PagesTest extends TestCase
{
    use RefreshDatabase;
    use StoresFiles;

    private User $admin;

    private User $cit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->useLocalUploads();
        Department::query()->forceCreate(['id' => 10, 'name' => 'College of IT', 'slug' => 'cit']);
        Department::query()->forceCreate(['id' => 11, 'name' => 'College of Business', 'slug' => 'cba']);
        $this->admin = User::factory()->superadmin()->create();
        $this->cit = User::factory()->forDepartment(10)->create();
    }

    /** The props of the island `$name` as the browser receives them. */
    private function props(TestResponse $response, string $name): array
    {
        $this->assertSame(1, preg_match('/data-island="'.preg_quote($name, '/').'"\s+data-props="([^"]*)"/', $response->getContent(), $match), "island {$name} is on the page");

        return json_decode(html_entity_decode($match[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_guests_are_sent_to_login(): void
    {
        foreach (['/admin/carousels', '/admin/navlinks', '/admin/departments/10/members'] as $url) {
            $this->get($url)->assertRedirect();
        }
    }

    // ---- carousels ---------------------------------------------------------------------------------------------

    private function slides(): array
    {
        $slide = fn (int $order, ?int $department, array $over = []) => CarouselItem::query()->forceCreate([
            'image_path' => "carousels/s{$order}.webp", 'order' => $order, 'department_id' => $department, 'show_on_main' => true, ...$over,
        ])->id;

        return [$slide(1, null, ['title' => 'Welcome']), $slide(2, 10), $slide(3, 11)];
    }

    public function test_the_designer_follows_the_admin_scope(): void
    {
        [$main, $cit, $cba] = $this->slides();
        $this->actingAs($this->admin);

        $all = $this->props($this->get('/admin/carousels')->assertOk(), 'admin/carousels/CarouselDesigner');
        $this->assertSame([$main, $cit, $cba], array_column($all['slides'], 'id'));
        $this->assertEquals(['initialId' => $main, 'scopeName' => 'All departments'], array_intersect_key($all, array_flip(['initialId', 'scopeName'])));
        $this->assertSame([11, 10], array_column($all['departmentField']['options'], 'id'));
        $this->assertSame([null, false, true], [$all['departmentField']['value'], $all['departmentField']['locked'], $all['departmentField']['allowMain']]);
        $this->assertSame('Welcome', $all['slides'][0]['title']);
        $this->assertSame('legacy-title', $all['slides'][0]['layers'][0]['id']);

        $cbaScope = $this->props($this->withHeader('Cookie', 'admin_scope=11')->get('/admin/carousels')->assertOk(), 'admin/carousels/CarouselDesigner');
        $this->assertSame([$cba], array_column($cbaScope['slides'], 'id'));
        $this->assertSame(['College of Business', 11], [$cbaScope['scopeName'], $cbaScope['departmentField']['value']]);

        $mainScope = $this->props($this->withHeader('Cookie', 'admin_scope=main')->get('/admin/carousels')->assertOk(), 'admin/carousels/CarouselDesigner');
        $this->assertSame([$main], array_column($mainScope['slides'], 'id'));
        $this->assertSame('Main page', $mainScope['scopeName']);
    }

    public function test_the_slide_query_parameter_picks_the_open_slide_when_it_exists(): void
    {
        [$main, $cit] = $this->slides();
        $this->actingAs($this->admin);
        $this->assertSame($cit, $this->props($this->get("/admin/carousels?slide={$cit}")->assertOk(), 'admin/carousels/CarouselDesigner')['initialId']);
        $this->assertSame($main, $this->props($this->get('/admin/carousels?slide=9999')->assertOk(), 'admin/carousels/CarouselDesigner')['initialId']);
    }

    public function test_a_department_user_designs_only_their_own_slides(): void
    {
        [, $cit] = $this->slides();
        $props = $this->props($this->actingAs($this->cit)->get('/admin/carousels')->assertOk(), 'admin/carousels/CarouselDesigner');
        $this->assertSame([$cit], array_column($props['slides'], 'id'));
        $this->assertSame([true, 10, null], [$props['departmentField']['locked'], $props['departmentField']['value'], $props['scopeName']]);
        $this->assertSame([10], array_column($props['departmentField']['options'], 'id'));
    }

    public function test_the_designer_opens_without_slides(): void
    {
        $props = $this->props($this->actingAs($this->admin)->get('/admin/carousels')->assertOk(), 'admin/carousels/CarouselDesigner');
        $this->assertSame([[], null], [$props['slides'], $props['initialId']]);
    }

    // ---- navigation links --------------------------------------------------------------------------------------

    public function test_the_navigation_editor_is_for_superadmins_only(): void
    {
        $this->actingAs($this->cit)->get('/admin/navlinks')->assertStatus(403);
    }

    public function test_the_navigation_editor_gets_the_tree_suggestions_and_known_paths(): void
    {
        $top = NavigationLink::query()->forceCreate(['label' => 'News', 'url' => '/posts', 'order' => 2, 'is_active' => true]);
        $first = NavigationLink::query()->forceCreate(['label' => 'Home', 'url' => '/', 'order' => 1, 'is_active' => true]);
        NavigationLink::query()->forceCreate(['label' => 'Hello', 'url' => '/posts/hello', 'order' => 1, 'is_active' => false, 'parent_id' => $top->id]);
        foreach (['hello', 'newer'] as $i => $slug) {
            Post::query()->forceCreate(['title' => "Post {$slug}", 'slug' => $slug, 'content' => 'x', 'type' => 'article', 'user_id' => $this->admin->id, 'created_at' => now()->addMinutes($i)]);
        }

        $props = $this->props($this->actingAs($this->admin)->get('/admin/navlinks')->assertOk(), 'admin/navlinks/NavLinksEditor');

        $this->assertSame([$first->id, $top->id], array_column($props['links'], 'id'));
        $this->assertSame(['Hello'], array_column($props['links'][1]['children'], 'label'));
        $this->assertFalse($props['links'][1]['children'][0]['isActive']);
        $this->assertSame([['name' => 'College of Business', 'slug' => 'cba'], ['name' => 'College of IT', 'slug' => 'cit']], $props['departments']);

        $suggestions = array_map(fn (array $s) => "{$s['group']}:{$s['url']}", $props['suggestions']);
        foreach (['Pages:/', 'Pages:/contact', 'Pages:#departments', 'Departments:/departments/cit', 'Posts:/posts/hello', 'Posts:/posts/newer'] as $expected) {
            $this->assertContains($expected, $suggestions);
        }
        foreach (['/posts', '/departments/cit', '/departments/cba', '/posts/hello'] as $path) {
            $this->assertContains($path, $props['knownPaths']);
        }
    }

    // ---- org chart ---------------------------------------------------------------------------------------------

    public function test_the_org_chart_gets_the_departments_members_in_display_order(): void
    {
        $boss = Member::query()->forceCreate(['name' => 'Boss', 'role' => 'Dean', 'department_id' => 10, 'order' => 2, 'image_path' => 'members/b.webp']);
        $report = Member::query()->forceCreate(['name' => 'Report', 'role' => 'Head', 'department_id' => 10, 'parent_id' => $boss->id, 'order' => 1]);
        $first = Member::query()->forceCreate(['name' => 'First', 'role' => 'Registrar', 'department_id' => 10, 'order' => 1]);
        Member::query()->forceCreate(['name' => 'Other', 'role' => 'x', 'department_id' => 11, 'order' => 1]);

        $response = $this->actingAs($this->cit)->get('/admin/departments/10/members')->assertOk()->assertSee('Faculty &amp; org chart', false)->assertSee('Org chart - College of IT', false);
        $props = $this->props($response, 'admin/members/OrgChartEditor');

        $this->assertSame([10, 'College of IT', 'cit'], [$props['departmentId'], $props['departmentName'], $props['departmentSlug']]);
        $this->assertSame([$report->id, $first->id, $boss->id], array_column($props['members'], 'id'));
        $this->assertSame(
            ['id' => $boss->id, 'name' => 'Boss', 'role' => 'Dean', 'parentId' => null, 'order' => 2, 'imageUrl' => '/storage/members/b.webp'],
            $props['members'][2],
        );
        $this->assertSame([$boss->id, null], [$props['members'][0]['parentId'], $props['members'][0]['imageUrl']]);
    }

    public function test_the_org_chart_is_limited_to_the_users_own_department(): void
    {
        $this->actingAs($this->cit)->get('/admin/departments/11/members')->assertStatus(403);
        $this->actingAs($this->admin)->get('/admin/departments/11/members')->assertOk();
        $this->get('/admin/departments/999/members')->assertStatus(404);
        $this->get('/admin/departments/abc/members')->assertStatus(404);
    }
}
