<?php

namespace Tests\Unit\Site;

use App\Site\PostFilters;
use App\Support\Pagination;
use PHPUnit\Framework\TestCase;

class PostFiltersTest extends TestCase
{
    private function parse(string $query): array
    {
        parse_str($query, $params);

        return PostFilters::parsePostFilters($params);
    }

    private function parseDownloads(string $query): array
    {
        parse_str($query, $params);

        return PostFilters::parseDownloadFilters($params);
    }

    public function test_department_id_accepts_positive_integers_only(): void
    {
        $this->assertSame(4, PostFilters::parseDepartmentId('4'));
        foreach ([null, '', '0', '-1', '1.5', 'abc'] as $value) {
            $this->assertNull(PostFilters::parseDepartmentId($value), (string) $value);
        }
    }

    public function test_search_is_trimmed_and_capped(): void
    {
        $this->assertSame('hello', PostFilters::parseSearch('  hello '));
        $this->assertSame('', PostFilters::parseSearch(null));
        $this->assertSame(100, mb_strlen(PostFilters::parseSearch(str_repeat('x', 300))));
    }

    public function test_post_filters_default_to_none_newest_first(): void
    {
        $this->assertSame(['type' => null, 'departmentId' => null, 'search' => null, 'sort' => 'newest'], $this->parse(''));
        $this->assertFalse(PostFilters::hasPostFilters($this->parse('')));
    }

    public function test_post_filters_read_every_filter_and_drop_malformed_values(): void
    {
        $this->assertSame(
            ['type' => 'event', 'departmentId' => 3, 'search' => 'mass', 'sort' => 'oldest'],
            $this->parse('type=event&department_id=3&search=%20mass%20&sort=oldest'),
        );
        $this->assertSame(
            ['type' => null, 'departmentId' => null, 'search' => null, 'sort' => 'newest'],
            $this->parse('type=bogus&department_id=-2&sort=sideways'),
        );
        $this->assertSame(['type' => null, 'departmentId' => null, 'search' => null, 'sort' => 'newest'], $this->parse('type[]=event&search[]=x'));
    }

    public function test_posts_href_leaves_defaults_out_of_the_url(): void
    {
        $this->assertSame('/posts', PostFilters::postsHref([]));
        $this->assertSame('/posts', PostFilters::postsHref(['sort' => 'newest'], 1));
        $this->assertSame('/posts?page=3', PostFilters::postsHref([], 3));
    }

    public function test_posts_href_round_trips_filters_and_page(): void
    {
        $filters = ['type' => 'announcement', 'departmentId' => 7, 'search' => 'enrol & more', 'sort' => 'oldest'];
        $url = PostFilters::postsHref($filters, 4);
        $this->assertSame('/posts', parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame($filters, PostFilters::parsePostFilters($params));
        $this->assertSame(4, Pagination::parsePage($params['page']));
        $this->assertSame($url, PostFilters::postsHref(PostFilters::parsePostFilters($params), Pagination::parsePage($params['page'])));
    }

    public function test_download_filters_default_to_none_and_drop_malformed_values(): void
    {
        $this->assertSame(['search' => null, 'departmentId' => null, 'directory' => null], $this->parseDownloads(''));
        $this->assertFalse(PostFilters::hasDownloadFilters($this->parseDownloads('department_id=0&search=%20&directory=%20')));
    }

    public function test_downloads_href_round_trips_search_department_and_directory(): void
    {
        $filters = ['search' => 'enrol & more', 'departmentId' => 7, 'directory' => 'Forms / 2026'];
        $url = PostFilters::downloadsHref($filters);
        $this->assertSame('/downloads', parse_url($url, PHP_URL_PATH));
        $this->assertSame($filters, $this->parseDownloads((string) parse_url($url, PHP_URL_QUERY)));
        $this->assertTrue(PostFilters::hasDownloadFilters($filters));
        $this->assertSame('/downloads', PostFilters::downloadsHref([]));
    }

    public function test_zero_is_a_real_filter_value(): void
    {
        $filters = $this->parse('search=0');
        $this->assertSame('0', $filters['search']);
        $this->assertTrue(PostFilters::hasPostFilters($filters));
        $this->assertSame('/posts?search=0', PostFilters::postsHref($filters));

        $downloads = $this->parseDownloads('directory=0&search=0');
        $this->assertSame(['search' => '0', 'departmentId' => null, 'directory' => '0'], $downloads);
        $this->assertTrue(PostFilters::hasDownloadFilters($downloads));
        $this->assertSame('/downloads?search=0&directory=0', PostFilters::downloadsHref($downloads));
    }

    public function test_the_first_duplicate_parameter_wins_and_array_parameters_are_absent(): void
    {
        $params = PostFilters::queryParams('type=event&type=article&search[]=x&search=a+b%26c&department_id=&flag');
        $this->assertSame('event', $params['type']);
        $this->assertSame('a b&c', $params['search']);
        $this->assertSame(['type' => 'event', 'departmentId' => null, 'search' => 'a b&c', 'sort' => 'newest'], PostFilters::parsePostFilters($params));
        $this->assertSame(
            ['type' => null, 'departmentId' => null, 'search' => null, 'sort' => 'newest'],
            PostFilters::parsePostFilters(PostFilters::queryParams('search[]=x&type[]=event')),
        );
    }

    public function test_the_search_cap_counts_utf16_units_like_js_slice(): void
    {
        // 😀 is two UTF-16 units: 100 units hold 50 of them.
        $this->assertSame(str_repeat('😀', 50), PostFilters::parseSearch(str_repeat('😀', 80)));
    }
}
