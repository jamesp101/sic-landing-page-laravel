<?php

namespace Tests\Unit\Site;

use App\Site\SiteNav;
use PHPUnit\Framework\TestCase;

class SiteNavTest extends TestCase
{
    private const DEPARTMENTS = [['name' => 'CIT', 'slug' => 'cit'], ['name' => 'CBA', 'slug' => 'cba']];

    private function link(int $id, string $label, string $url, array $children = []): array
    {
        return ['id' => $id, 'label' => $label, 'url' => $url, 'children' => $children];
    }

    public function test_header_falls_back_to_the_default_menu_while_no_links_are_stored(): void
    {
        $items = SiteNav::headerItems([], self::DEPARTMENTS);
        $this->assertSame(array_column(SiteNav::DEFAULT_NAV_LINKS, 'label'), array_column($items, 'label'));
        $this->assertSame([
            'label' => 'Departments',
            'children' => [['label' => 'CIT', 'href' => '/departments/cit'], ['label' => 'CBA', 'href' => '/departments/cba']],
        ], $items[1]);
        $this->assertSame(['label' => 'Home', 'href' => '/'], $items[0]);
    }

    public function test_default_menu_leaves_the_departments_menu_out_when_there_are_no_departments(): void
    {
        $this->assertSame(['Home', 'News & Events', 'Downloads', 'Contact'], array_column(SiteNav::headerItems([], []), 'label'));
    }

    public function test_the_departments_menu_link_becomes_a_dropdown_of_every_department_ignoring_its_own_children(): void
    {
        $items = SiteNav::headerItems([$this->link(1, 'Schools', SiteNav::DEPARTMENTS_MENU_URL, [$this->link(2, 'x', '/x')])], self::DEPARTMENTS);
        $this->assertSame([[
            'label' => 'Schools',
            'children' => [['label' => 'CIT', 'href' => '/departments/cit'], ['label' => 'CBA', 'href' => '/departments/cba']],
        ]], $items);
    }

    public function test_parents_with_sub_links_become_dropdowns_and_the_rest_plain_links(): void
    {
        $items = SiteNav::headerItems([
            $this->link(1, 'About', '/about', [$this->link(2, 'Team', '/team')]),
            $this->link(3, 'Contact', '/contact'),
        ], []);
        $this->assertSame([
            ['label' => 'About', 'children' => [['label' => 'Team', 'href' => '/team']]],
            ['label' => 'Contact', 'href' => '/contact'],
        ], $items);
    }

    public function test_footer_lists_every_department_and_the_quick_links(): void
    {
        $footer = SiteNav::footerItems(self::DEPARTMENTS);
        $this->assertSame([['label' => 'CIT', 'href' => '/departments/cit'], ['label' => 'CBA', 'href' => '/departments/cba']], $footer['departments']);
        $this->assertSame(['/posts', '/downloads', '/contact', '/rss.xml'], array_column($footer['quickLinks'], 'href'));
    }

    public function test_footer_points_at_the_home_page_departments_section_while_there_are_none(): void
    {
        $this->assertSame([['label' => 'Academic programs', 'href' => '/#departments']], SiteNav::footerItems([])['departments']);
    }

    public function test_department_href_and_sitemap_pages_are_valid_link_targets(): void
    {
        $this->assertSame('/departments/cit', SiteNav::departmentHref('cit'));
        $this->assertSame('/posts/a', SiteNav::postHref('a'));
        foreach (SiteNav::SITEMAP_PAGES as $path) {
            $this->assertContains($path, SiteNav::STATIC_PATHS);
        }
    }
}
