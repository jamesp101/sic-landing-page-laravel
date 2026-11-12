<?php

namespace Tests\Feature\Site;

use App\Content\Content;
use App\Models\NavigationLink;
use App\Models\Post;
use App\Site\Queries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostsPageTest extends TestCase
{
    use RefreshDatabase, SeedsSite;

    private const SITE = 'http://localhost/';

    private string $long;

    private string $blockContent;

    private string $markdownContent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->long = str_repeat('Plenty of words about enrolment and orientation week. ', 10);
        $this->blockContent = json_encode([
            'version' => 1,
            'blocks' => [
                ['id' => 'h', 'type' => 'heading', 'text' => 'Welcome', 'level' => 2],
                ['id' => 'p', 'type' => 'paragraph', 'text' => $this->long],
            ],
        ]);
        $this->markdownContent = "# Title\n\n**Bold** {$this->long}";

        $this->seedOwner();
        $this->makeDepartment('CIT', 'cit', ['id' => 1]);
        $this->makeDepartment('CBA', 'cba', ['id' => 2]);
        $this->makePost('block', [
            'content' => $this->blockContent, 'search_text' => Content::searchTextOf($this->blockContent),
            'highlight_image_path' => 'posts/abc.webp', 'created_at' => $this->day(10),
        ]);
        $this->makePost('markdown', ['content' => $this->markdownContent, 'search_text' => Content::searchTextOf($this->markdownContent), 'created_at' => $this->day(9)]);
        // Never backfilled: no search_text, so the content is read.
        $this->makePost('legacy', ['content' => $this->markdownContent, 'search_text' => '', 'created_at' => $this->day(8)]);
        $this->makePost('empty', ['content' => '', 'search_text' => '', 'created_at' => $this->day(7)]);
    }

    private function excerpt(array $items, string $slug): ?string
    {
        foreach ($items as $item) {
            if ($item['slug'] === $slug) {
                return $item['excerpt'];
            }
        }

        return null;
    }

    public function test_posts_page_hands_over_the_layout_beside_the_list_and_is_null_past_the_last_page(): void
    {
        $page = Queries::getPostsPage(['sort' => 'newest'], 1, 10, self::SITE);
        $this->assertCount(4, $page['result']['items']);
        $this->assertSame(['CBA', 'CIT'], array_column($page['layout']['departments'], 'name'));
        $this->assertSame([], $page['layout']['navLinks']);
        $this->assertNull(Queries::getPostsPage(['sort' => 'newest'], 2, 10, self::SITE));
    }

    public function test_excerpts_equal_parsing_the_content_for_block_markdown_and_unindexed_posts(): void
    {
        $items = Queries::listPosts([], 10)['items'];
        $this->assertSame(Content::excerptOf($this->blockContent), $this->excerpt($items, 'block'));
        $this->assertSame(Content::excerptOf($this->markdownContent), $this->excerpt($items, 'markdown'));
        $this->assertSame(Content::excerptOf($this->markdownContent), $this->excerpt($items, 'legacy'));
        $this->assertSame('', $this->excerpt($items, 'empty'));
        $this->assertTrue(str_ends_with($this->excerpt($items, 'block'), '…'));
        $this->assertSame(200, mb_strlen($this->excerpt($items, 'block')));
    }

    public function test_the_card_image_variant_of_a_highlight_image(): void
    {
        $items = Queries::listPosts([], 10)['items'];
        $by = array_column($items, null, 'slug');
        $this->assertSame('/storage/posts/abc.webp', $by['block']['imageUrl']);
        $this->assertSame('/storage/posts/abc.w640.webp', $by['block']['imageSmallUrl']);
        $this->assertNull($by['markdown']['imageSmallUrl']);
    }

    public function test_listing_loads_content_only_for_posts_without_search_text(): void
    {
        DB::enableQueryLog();
        Queries::listPosts([], 10);
        foreach (DB::getQueryLog() as $query) {
            if (preg_match('/["`]content["`]/', $query['query'])) {
                $this->assertStringContainsString(' in (', $query['query']);
            }
        }
    }

    public function test_an_unknown_post_starts_no_layout_query_and_a_found_one_hands_them_over(): void
    {
        DB::enableQueryLog();
        $this->assertNull(Queries::getPostPage('nope', self::SITE));
        $this->assertStringNotContainsString('navigation_links', implode(' ', array_column(DB::getQueryLog(), 'query')));
        $page = Queries::getPostPage('markdown', self::SITE);
        $this->assertIsArray($page['layout']['navLinks']);
        $this->assertIsArray($page['layout']['departments']);
    }

    public function test_the_post_page_excerpt_comes_from_the_content(): void
    {
        $page = Queries::getPostPage('markdown', self::SITE);
        $this->assertSame(Content::excerptOf($this->markdownContent), $page['post']['excerpt']);
        $this->assertContains(Content::excerptOf($this->blockContent), array_column($page['related'], 'excerpt'));
    }

    public function test_a_hidden_author_is_dropped_and_the_json_ld_is_attributed_to_the_college(): void
    {
        $this->makePost('anonymous', ['hide_author' => true, 'created_at' => $this->day(6)]);
        $hidden = Queries::getPostPage('anonymous', self::SITE);
        $this->assertNull($hidden['post']['authorName']);
        $this->assertStringNotContainsString('Owner', json_encode($hidden['seo']));

        $shown = Queries::getPostPage('markdown', self::SITE);
        $this->assertSame('Owner', $shown['post']['authorName']);
        $this->assertStringContainsString('"name":"Owner"', json_encode($shown['seo']));
    }

    public function test_related_posts_list_the_newest_of_the_same_department_then_the_newest_of_the_same_type(): void
    {
        Post::query()->delete();
        $this->makePost('d1', ['department_id' => 1, 'created_at' => $this->day(1)]);
        $this->makePost('d2', ['department_id' => 1, 'created_at' => $this->day(2)]);
        $this->makePost('t-new', ['created_at' => $this->day(9)]);
        $this->makePost('t-mid', ['department_id' => 2, 'created_at' => $this->day(8)]);
        $this->makePost('t-old', ['created_at' => $this->day(3)]);
        $this->makePost('event', ['type' => 'event', 'created_at' => $this->day(20)]);
        $this->makePost('self', ['department_id' => 1, 'created_at' => $this->day(5)]);

        $slugs = fn (string $slug) => array_column(Queries::getPostPage($slug, self::SITE)['related'], 'slug');
        $this->assertSame(['d2', 'd1', 't-new'], $slugs('self'));
        $this->assertSame(['t-new', 't-mid', 'self'], $slugs('t-old'));
        $this->assertSame([], $slugs('event'));
    }

    public function test_nav_links_nest_active_children_under_active_top_level_links_in_order(): void
    {
        $top = fn (string $label, int $order, bool $active = true) => NavigationLink::query()->create(['label' => $label, 'url' => "/{$label}", 'order' => $order, 'is_active' => $active]);
        $child = fn (string $label, int $order, int $parentId, bool $active = true) => NavigationLink::query()->create(['label' => $label, 'url' => "/{$label}", 'order' => $order, 'is_active' => $active, 'parent_id' => $parentId]);
        $b = $top('b', 2);
        $top('a', 1);
        $hidden = $top('hidden', 3, false);
        $child('b2', 2, $b->id);
        $child('b1', 1, $b->id);
        $child('b-off', 3, $b->id, false);
        $child('orphan', 1, $hidden->id);

        $links = Queries::getNavLinks();
        $this->assertSame([['a', []], ['b', ['b1', 'b2']]], array_map(fn (array $l) => [$l['label'], array_column($l['children'], 'label')], $links));
    }

    public function test_filters_and_pagination(): void
    {
        $result = Queries::listPosts(['search' => 'orientation'], 10);
        $this->assertSame(['block', 'markdown'], array_column($result['items'], 'slug'));
        $this->assertSame(1, $result['pageCount']);

        $this->assertSame(['empty', 'legacy'], array_column(Queries::listPosts(['sort' => 'oldest'], 2)['items'], 'slug'));
        $this->assertSame(['block', 'markdown'], array_column(Queries::listPosts([], 2, 1)['items'], 'slug'));
        $this->assertSame(['legacy', 'empty'], array_column(Queries::listPosts([], 2, 2)['items'], 'slug'));
        $this->assertSame(2, Queries::listPosts([], 2)['pageCount']);
        $this->assertSame([], Queries::listPosts(['search' => '%'], 10)['items']);
    }
}
