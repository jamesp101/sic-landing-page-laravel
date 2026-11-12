<?php

namespace Tests\Unit\Admin\Trees;

use App\Admin\NavTree;
use PHPUnit\Framework\TestCase;

/** Port of the server parts of nav-tree.test.ts (the drag/indent/undo helpers stay client-side). */
class NavTreeTest extends TestCase
{
    private static function n(int $id, array $children = [], array $over = []): array
    {
        return ['id' => $id, 'label' => "L{$id}", 'url' => '/', 'isActive' => true, 'order' => $id, 'children' => $children, ...$over];
    }

    private static function link(int $id, string $url, array $over = []): array
    {
        return self::n($id, [], ['url' => $url, ...$over]);
    }

    public function test_the_payload_round_trips_the_tree_structure(): void
    {
        $tree = [self::n(1), self::n(2), self::n(3, [self::n(4), self::n(5)])];
        $payload = NavTree::toPayload($tree);
        $this->assertContains(['id' => 5, 'parentId' => 3, 'order' => 2], $payload);
        $this->assertSame([1, 2, 3, 4, 5], array_column($payload, 'id'));
        $this->assertTrue(NavTree::samePayload($payload, NavTree::toPayload(NavTree::renumber($tree))));
        $this->assertFalse(NavTree::samePayload($payload, array_reverse($payload)));
    }

    public function test_renumber_numbers_siblings_from_one(): void
    {
        $renumbered = NavTree::renumber([self::n(7, [self::n(9), self::n(8)]), self::n(5)]);
        $this->assertSame([1, 2], array_column($renumbered, 'order'));
        $this->assertSame([1, 2], array_column($renumbered[0]['children'], 'order'));
    }

    // ---- reorderError ------------------------------------------------------------------------------------------

    private function existing(): array
    {
        return [['id' => 1, 'parentId' => null], ['id' => 2, 'parentId' => null], ['id' => 3, 'parentId' => 2]];
    }

    public function test_reorder_accepts_a_valid_tree(): void
    {
        $this->assertNull(NavTree::reorderError(
            [['id' => 2, 'parentId' => null, 'order' => 1], ['id' => 1, 'parentId' => 2, 'order' => 1]],
            [['id' => 1, 'parentId' => null], ['id' => 2, 'parentId' => null]],
        ));
    }

    public function test_reorder_rejects_unknown_ids_duplicates_and_self_parents(): void
    {
        $this->assertMatchesRegularExpression('/no longer exists/', NavTree::reorderError([['id' => 9, 'parentId' => null, 'order' => 1]], $this->existing()));
        $this->assertMatchesRegularExpression('/more than once/', NavTree::reorderError([['id' => 1, 'parentId' => null, 'order' => 1], ['id' => 1, 'parentId' => null, 'order' => 2]], $this->existing()));
        $this->assertMatchesRegularExpression('/own parent/', NavTree::reorderError([['id' => 1, 'parentId' => 1, 'order' => 1]], $this->existing()));
    }

    public function test_reorder_rejects_a_third_level(): void
    {
        $this->assertMatchesRegularExpression('/two levels/', NavTree::reorderError([['id' => 2, 'parentId' => 1, 'order' => 1]], $this->existing()));
        $this->assertMatchesRegularExpression('/own parent/', NavTree::reorderError([['id' => 3, 'parentId' => 3, 'order' => 1]], $this->existing()));
        $this->assertMatchesRegularExpression('/two levels/', NavTree::reorderError([['id' => 1, 'parentId' => 3, 'order' => 1]], $this->existing()));
    }

    public function test_reorder_rejects_a_parent_that_does_not_exist(): void
    {
        $this->assertSame('The selected parent link does not exist.', NavTree::reorderError([['id' => 1, 'parentId' => 99, 'order' => 1]], $this->existing()));
    }

    // ---- navUrlError -------------------------------------------------------------------------------------------

    public function test_nav_url_allows_paths_anchors_and_safe_schemes(): void
    {
        foreach (['/', '/posts', '#departments', 'https://example.com/a', 'mailto:a@b.co', 'tel:+6312345', '  /padded  '] as $url) {
            $this->assertNull(NavTree::navUrlError($url), $url);
        }
    }

    public function test_nav_url_rejects_unsafe_or_malformed_values(): void
    {
        foreach (['', 'javascript:alert(1)', 'data:text/html,x', '//evil.com', 'posts', '/a b', "/a\u{00a0}b", 'https://', 'ftp://x.test'] as $url) {
            $this->assertNotNull(NavTree::navUrlError($url), $url);
        }
        $this->assertSame('The URL is required.', NavTree::navUrlError('  '));
        $this->assertSame('The URL must not contain spaces.', NavTree::navUrlError('/a b'));
        $this->assertSame('Use a path such as /posts or a full https:// URL.', NavTree::navUrlError('//evil.com'));
    }

    // ---- classifyUrl / internalPath / postSlugsLinked ----------------------------------------------------------

    public function test_classify_detects_the_link_type(): void
    {
        foreach ([
            ['/', 'page'], ['/posts?type=event', 'page'], ['/departments/cba', 'department'], ['/posts/hello-world', 'post'],
            ['#departments', 'departments-menu'], ['#contact', 'anchor'], ['https://example.com', 'external'], ['mailto:a@b.c', 'email'],
            ['tel:+63', 'phone'], ['  ', null], ['nonsense', null],
        ] as [$url, $kind]) {
            $this->assertSame($kind, NavTree::classifyUrl($url), $url);
        }
    }

    public function test_internal_path_ignores_query_hash_and_trailing_slash(): void
    {
        $this->assertSame('/posts', NavTree::internalPath('/posts/?type=event#x'));
        $this->assertSame('/', NavTree::internalPath('/'));
        $this->assertSame('/', NavTree::internalPath('/?a=1'));
        $this->assertSame('/posts/a b', NavTree::internalPath('/posts/a%20b'));
        $this->assertSame('/posts/a%2Fb', NavTree::internalPath('/posts/a%2fb'));
        $this->assertSame('/posts/%E0%A4%A', NavTree::internalPath('/posts/%E0%A4%A'));
    }

    public function test_post_slugs_linked_lists_each_slug_once(): void
    {
        $this->assertSame(['hello', 'other'], NavTree::postSlugsLinked([
            '/posts/hello', '/posts/hello/?x=1#y', '/posts/other', '/posts', '/posts/a/b', '//posts/evil', 'https://example.com/posts/ext', '/departments/cba',
        ]));
    }

    // ---- navWarnings -------------------------------------------------------------------------------------------

    private const KNOWN = ['/', '/posts', '/departments/cba', '/posts/hello'];

    public function test_warnings_flag_internal_paths_that_match_nothing(): void
    {
        $tree = [
            self::link(1, '/posts?type=event'), self::link(2, '/missing'), self::link(3, '/departments/cba#top'),
            self::link(4, '/storage/file.pdf'), self::link(5, 'https://example.com/missing'), self::link(6, '#nowhere'),
        ];
        $warnings = NavTree::navWarnings($tree, self::KNOWN);
        $this->assertSame([2], array_keys($warnings));
        $this->assertSame('internal-missing', $warnings[2][0]['kind']);
        $this->assertSame('No page, department or post exists at /missing. Visitors would see a “not found” page.', $warnings[2][0]['message']);
    }

    public function test_warnings_flag_visible_duplicates_at_the_same_level_only(): void
    {
        $tree = [
            self::link(1, '/posts'), self::link(2, '/posts'),
            self::link(3, '/', ['children' => [self::link(4, '/posts'), self::link(5, '/posts', ['isActive' => false])]]),
            self::link(6, '/posts', ['isActive' => false]),
        ];
        $warnings = NavTree::navWarnings($tree, self::KNOWN);
        $this->assertSame(['duplicate'], array_column($warnings[1], 'kind'));
        $this->assertSame(['duplicate'], array_column($warnings[2], 'kind'));
        $this->assertSame('Another visible link at the same level also point to /posts.', $warnings[1][0]['message']);
        foreach ([4, 5, 6] as $id) {
            $this->assertArrayNotHasKey($id, $warnings);
        }
    }

    public function test_warnings_skip_links_whose_url_is_not_used_because_they_open_a_dropdown(): void
    {
        $tree = [self::link(1, '/missing', ['children' => [self::link(2, '/ok-child')]]), self::link(3, '#departments')];
        $this->assertSame([2], array_keys(NavTree::navWarnings($tree, self::KNOWN)));
    }

    // ---- proposeNavTree ----------------------------------------------------------------------------------------

    public function test_propose_returns_the_renumbered_payload_of_a_valid_tree(): void
    {
        $current = [self::n(1), self::n(2), self::n(3, [self::n(4)])];
        $this->assertSame(['change' => ['kind' => 'tree', 'items' => [
            ['id' => 2, 'parentId' => null, 'order' => 1],
            ['id' => 1, 'parentId' => null, 'order' => 2],
            ['id' => 3, 'parentId' => null, 'order' => 3],
            ['id' => 4, 'parentId' => 3, 'order' => 1],
        ]]], NavTree::proposeNavTree($current, [self::n(2), self::n(1), self::n(3, [self::n(4)])]));
    }

    public function test_propose_refuses_a_third_level_with_the_same_message_as_the_reorder_action(): void
    {
        $current = [self::n(1), self::n(2), self::n(3, [self::n(4)])];
        $serverMessage = NavTree::reorderError([['id' => 3, 'parentId' => 1, 'order' => 1]], NavTree::toPayload($current));
        $this->assertMatchesRegularExpression('/two levels/', $serverMessage);
        $this->assertSame(['error' => $serverMessage], NavTree::proposeNavTree($current, [self::n(1, [self::n(3, [self::n(4)])]), self::n(2)]));
    }
}
