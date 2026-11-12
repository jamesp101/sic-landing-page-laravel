<?php

namespace Tests\Unit\Site;

use App\Site\Seo;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SeoTest extends TestCase
{
    private const SITE = 'https://sic.example/';

    private function postRow(array $over = []): array
    {
        return [
            'id' => 1, 'title' => 'Foundation Day', 'slug' => 'foundation-day', 'type' => 'article',
            'excerpt' => 'A day to remember.', 'imageUrl' => '/storage/a.webp', 'imageSmallUrl' => null,
            'startDate' => null, 'endDate' => null, 'isFeatured' => false,
            'createdAt' => new CarbonImmutable('2026-01-01T00:00:00Z'),
            'updatedAt' => new CarbonImmutable('2026-01-02T00:00:00Z'),
            'department' => null, 'html' => '<p>x</p>', 'authorName' => 'Admin',
            ...$over,
        ];
    }

    private function departmentRow(array $over = []): array
    {
        return [
            'id' => 2, 'name' => 'Grade School', 'slug' => 'grade-school', 'description' => null, 'logoUrl' => null,
            'updatedAt' => new CarbonImmutable('2026-01-03T00:00:00Z'), 'heroContent' => null,
            ...$over,
        ];
    }

    public function test_site_url_prefers_the_configured_site_and_falls_back_to_the_request_origin(): void
    {
        $this->assertSame('https://sic.example/', Seo::siteUrl(['site' => 'https://sic.example', 'url' => 'http://localhost:4321/x']));
        $this->assertSame('http://localhost:4321/', Seo::siteUrl(['site' => null, 'url' => 'http://localhost:4321/x']));
    }

    public function test_canonical_is_absolute_and_query_free_by_default_explicit_when_given(): void
    {
        $context = ['site' => 'https://sic.example', 'url' => 'https://sic.example/posts'];
        $this->assertSame('https://sic.example/posts', Seo::canonicalHref(null, $context));
        $this->assertSame('https://sic.example/posts?page=2', Seo::canonicalHref('/posts?page=2', $context));
        $this->assertSame('https://other.example/a', Seo::canonicalHref('https://other.example/a', $context));
    }

    public function test_meta_description_collapses_whitespace_and_clamps_once_at_the_limit(): void
    {
        $this->assertSame('one two', Seo::metaDescription("  one \n\n two  "));
        $this->assertSame('', Seo::metaDescription(null));
        $long = Seo::metaDescription(str_repeat('word ', 100));
        $this->assertLessThanOrEqual(Seo::META_DESCRIPTION_MAX, mb_strlen($long));
        $this->assertTrue(str_ends_with($long, '…'));
        $this->assertSame(Seo::META_DESCRIPTION_MAX, mb_strlen(Seo::metaDescription(str_repeat('x', Seo::META_DESCRIPTION_MAX))));
    }

    public function test_post_seo_has_canonical_path_absolute_json_ld_url_and_title_fallback_description(): void
    {
        $seo = Seo::postPageSeo($this->postRow(), self::SITE);
        $this->assertSame('Foundation Day', $seo['title']);
        $this->assertSame('A day to remember.', $seo['description']);
        $this->assertSame('/posts/foundation-day', $seo['canonical']);
        $this->assertSame('article', $seo['type']);
        $this->assertSame('/storage/a.webp', $seo['image']);
        $this->assertSame(Seo::absoluteUrl('/posts/foundation-day', self::SITE), $seo['jsonLd']['mainEntityOfPage']['@id']);
        $this->assertSame(['label' => 'Foundation Day'], end($seo['breadcrumbs']));
        $this->assertSame('Foundation Day', Seo::postPageSeo($this->postRow(['excerpt' => '']), self::SITE)['description']);
    }

    public function test_post_json_ld_is_an_event_for_dated_events_and_attributes_hidden_authors_to_the_college(): void
    {
        $event = Seo::postJsonLd($this->postRow(['type' => 'event', 'startDate' => new CarbonImmutable('2026-02-01T08:00:00Z')]), self::SITE, 'https://sic.example/posts/x');
        $this->assertSame('Event', $event['@type']);
        $this->assertSame('2026-02-01T08:00:00.000Z', $event['startDate']);
        $this->assertArrayNotHasKey('endDate', $event);

        $article = Seo::postJsonLd($this->postRow(['authorName' => null]), self::SITE, 'https://sic.example/posts/x');
        $this->assertSame('NewsArticle', $article['@type']);
        $this->assertSame(['@id' => 'https://sic.example/#organization'], $article['author']);
        $this->assertSame('2026-01-01T00:00:00.000Z', $article['datePublished']);
    }

    public function test_department_seo_description_fallback_and_image_precedence(): void
    {
        $fallback = 'Grade School at San Isidro College, Malaybalay City, Bukidnon.';
        $this->assertSame($fallback, Seo::departmentPageSeo($this->departmentRow(), [], null, self::SITE)['description']);
        $this->assertSame($fallback, Seo::departmentPageSeo($this->departmentRow(['description' => '   ']), [], null, self::SITE)['description']);
        $this->assertSame('Teaching.', Seo::departmentPageSeo($this->departmentRow(['description' => 'Teaching.']), [], null, self::SITE)['description']);
        $this->assertSame('/logo.webp', Seo::departmentPageSeo($this->departmentRow(['logoUrl' => '/logo.webp']), [], '/slide.webp', self::SITE)['image']);
        $this->assertSame('/slide.webp', Seo::departmentPageSeo($this->departmentRow(), [], '/slide.webp', self::SITE)['image']);
    }

    public function test_posts_list_seo_filtered_views_are_noindex_and_canonical_keeps_only_the_page(): void
    {
        $base = Seo::postsListSeo([], ['sort' => 'newest'], 1, self::SITE);
        $this->assertSame('/posts', $base['canonical']);
        $this->assertFalse($base['noindex']);
        $this->assertSame('News, Events & Announcements', $base['title']);

        $filtered = Seo::postsListSeo([], ['type' => 'event', 'sort' => 'newest'], 3, self::SITE);
        $this->assertSame('/posts?page=3', $filtered['canonical']);
        $this->assertTrue($filtered['noindex']);
        $this->assertTrue($filtered['follow']);
        $this->assertSame('News, Events & Announcements (page 3)', $filtered['title']);
    }

    public function test_json_ld_script_escapes_the_less_than_sign(): void
    {
        $this->assertStringNotContainsString('<', Seo::jsonLdScript(['name' => '</script><b>']));
    }
}
