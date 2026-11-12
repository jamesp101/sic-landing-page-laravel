<?php

namespace Tests\Feature\Site;

use App\Content\WelcomeHero;
use App\Models\Member;
use App\Models\Setting;
use App\Site\Queries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PageDataTest extends TestCase
{
    use RefreshDatabase, SeedsSite;

    private const SITE = 'http://localhost/';

    private int $citId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOwner();
        $this->citId = $this->makeDepartment('CIT', 'cit')->id;
        $cbaId = $this->makeDepartment('CBA', 'cba')->id;

        for ($n = 1; $n <= 8; $n++) {
            $this->makePost("cit-{$n}", ['department_id' => $this->citId, 'created_at' => $this->day($n)]);
        }
        $this->makePost('cba-1', ['department_id' => $cbaId, 'created_at' => $this->day(20)]);
        $this->makePost('main-1', ['created_at' => $this->day(5)]);

        $this->makeSlide('carousels/b.webp', ['order' => 2]);
        $this->makeSlide('carousels/a.webp', ['order' => 1]);
        $this->makeSlide('carousels/hidden.webp', ['order' => 0, 'show_on_main' => false]);
        $this->makeSlide('carousels/cit-2.webp', ['order' => 2, 'department_id' => $this->citId, 'show_on_main' => false]);
        $this->makeSlide('carousels/cit-1.webp', ['order' => 1, 'department_id' => $this->citId, 'show_on_main' => false]);

        $this->makeHero('main-second', ['order' => 2]);
        $this->makeHero('main-first', ['order' => 1]);
        $this->makeHero('main-off', ['order' => 0, 'is_active' => false]);
        $this->makeHero('cit-hero', ['department_id' => $this->citId]);
        $this->makeHero('cba-hero', ['department_id' => $cbaId]);

        $dean = Member::query()->create(['name' => 'Dean', 'role' => 'Dean', 'department_id' => $this->citId]);
        Member::query()->create(['name' => 'Staff', 'role' => 'Staff', 'department_id' => $this->citId, 'parent_id' => $dean->id]);
        Member::query()->create(['name' => 'Other', 'role' => 'Dean', 'department_id' => $cbaId]);
    }

    public function test_home_data_reads_the_main_carousel_newest_posts_main_hero_sections_and_departments(): void
    {
        WelcomeHero::setWelcomeHero('Hello');
        $home = Queries::getHomeData();

        $this->assertSame(['/storage/carousels/a.webp', '/storage/carousels/b.webp'], array_column($home['carousel'], 'imageUrl'));
        $this->assertSame(['cba-1', 'cit-8', 'cit-7', 'cit-6', 'main-1', 'cit-5'], array_column($home['latestPosts'], 'slug'));
        $this->assertSame(['main-first', 'main-second'], array_column($home['heroSections'], 'title'));
        $this->assertSame(['cba', 'cit'], array_column($home['layout']['departments'], 'slug'));
        $this->assertSame('Hello', $home['welcomeHero']);
    }

    public function test_home_has_no_welcome_hero_until_one_is_saved(): void
    {
        Setting::query()->delete();
        $this->assertNull(Queries::getHomeData()['welcomeHero']);
    }

    public function test_an_unknown_department_is_null_and_runs_no_layout_query(): void
    {
        DB::enableQueryLog();
        $this->assertNull(Queries::getDepartmentPage('nope', self::SITE));
        $tables = implode(' ', array_column(DB::getQueryLog(), 'query'));
        $this->assertStringNotContainsString('navigation_links', $tables);
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_a_department_page_hands_over_the_layout(): void
    {
        $page = Queries::getDepartmentPage('cit', self::SITE);
        $this->assertContains('CIT', array_column($page['layout']['departments'], 'name'));
        $this->assertIsArray($page['layout']['navLinks']);
    }

    public function test_a_department_page_reads_its_six_newest_posts_with_total_slides_people_and_hero_sections(): void
    {
        $page = Queries::getDepartmentPage('cit', self::SITE);

        $this->assertSame('CIT', $page['department']['name']);
        $this->assertSame(['cit-8', 'cit-7', 'cit-6', 'cit-5', 'cit-4', 'cit-3'], array_column($page['posts'], 'slug'));
        $this->assertSame(8, $page['postCount']);
        $this->assertSame(['/storage/carousels/cit-1.webp', '/storage/carousels/cit-2.webp'], array_column($page['carousel'], 'imageUrl'));
        $this->assertSame(['cit-hero'], array_column($page['heroSections'], 'title'));
        $this->assertSame([['Dean', ['Staff']]], array_map(fn (array $m) => [$m['name'], array_column($m['children'], 'name')], $page['members']));
        $this->assertSame('/departments/cit', $page['seo']['canonical']);
    }

    public function test_a_department_with_fewer_than_six_posts_counts_only_its_own(): void
    {
        $page = Queries::getDepartmentPage('cba', self::SITE);
        $this->assertSame(['cba-1'], array_column($page['posts'], 'slug'));
        $this->assertSame(1, $page['postCount']);
    }
}
