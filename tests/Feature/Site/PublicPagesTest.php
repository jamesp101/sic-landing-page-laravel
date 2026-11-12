<?php

namespace Tests\Feature\Site;

use App\Carousel\Overlay;
use App\Models\Department;
use App\Models\NavigationLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase, SeedsSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedOwner();
        $dept = $this->makeDepartment('College of IT', 'cit', ['description' => 'Computing.']);
        $this->makeDepartment('Business', 'cba');
        foreach (range(1, 14) as $n) {
            $this->makePost("post-{$n}", [
                'title' => "Post number {$n}", 'type' => $n % 2 ? 'article' : 'event', 'department_id' => $n <= 3 ? $dept->id : null,
                'created_at' => $this->day($n), 'search_text' => "Body of post {$n} about enrolment",
                'start_date' => $n % 2 ? null : $this->day($n),
            ]);
        }
        $this->makeDownload('Handbook', 'Forms', $dept->id, '["students"]');
        NavigationLink::query()->create(['label' => 'Home', 'url' => '/', 'order' => 1]);
    }

    public function test_every_public_url_answers_200_with_seeded_rows(): void
    {
        foreach (['/', '/posts', '/posts/post-1', '/posts/post-2', '/departments/cit', '/downloads', '/contact'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_unknown_slugs_and_pages_past_the_end_answer_404_with_the_public_error_page(): void
    {
        $this->get('/posts/nope')->assertNotFound()->assertSee('Page not found')->assertSee('Skip to main content');
        $this->get('/departments/nope')->assertNotFound();
        $this->get('/posts?page=3')->assertNotFound();
        $this->get('/posts?page=2')->assertOk();
    }

    public function test_the_styleguide_is_only_available_locally(): void
    {
        $this->app['env'] = 'production';
        $this->get('/styleguide')->assertNotFound();
        $this->app['env'] = 'local';
        $this->get('/styleguide')->assertOk()->assertSee('Design system');
    }

    public function test_the_home_page_has_landmarks_skip_link_departments_and_latest_posts(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Skip to main content')
            ->assertSee('<main id="main"', false)
            ->assertSee('Our departments')
            ->assertSee('/departments/cit', false)
            ->assertSee('Post number 14')
            ->assertSee('application/ld+json', false)
            ->assertSee('<link rel="canonical"', false);
    }

    public function test_posts_filter_and_paginate(): void
    {
        $this->get('/posts')->assertOk()->assertSee('Showing 1–12 of 14 posts')->assertSee('rel="next"', false);
        $this->get('/posts?page=2')->assertOk()->assertSee('Showing 13–14 of 14 posts')->assertSee('rel="prev"', false);
        $this->get('/posts?type=event')->assertOk()->assertSee('7 posts matching your filters.')->assertSee('noindex, follow');
        $this->get('/posts?search=post+of+nothing')->assertOk()->assertSee('No posts found');
        $this->get('/posts?sort=oldest')->assertOk()->assertSeeInOrder(['Post number 1', 'Post number 2']);
        $this->get('/posts?department_id=1')->assertOk()->assertSee('3 posts matching your filters.');
    }

    public function test_the_post_page_shows_the_article_with_seo_and_related_posts(): void
    {
        $this->get('/posts/post-2')
            ->assertOk()
            ->assertSee('<h1', false)
            ->assertSee('Event details')
            ->assertSee('Related posts')
            ->assertSee('"@type":"Event"', false)
            ->assertSee('property="og:type" content="article"', false);
    }

    public function test_the_department_page_lists_its_posts(): void
    {
        $this->get('/departments/cit')
            ->assertOk()
            ->assertSee('College of IT')
            ->assertSee('View all 3 from this department')
            ->assertSee('Computing.');
    }

    public function test_downloads_filter_by_folder_and_search(): void
    {
        $this->get('/downloads')->assertOk()->assertSee('Handbook')->assertSee('Folders');
        $this->get('/downloads?directory=Forms')->assertOk()->assertSee('Handbook')->assertSee('noindex, follow');
        $this->get('/downloads?search=zzz')->assertOk()->assertSee('No files found');
    }

    public function test_the_sitemap_lists_pages_departments_and_posts(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();
        $this->assertSame('application/xml; charset=utf-8', $response->headers->get('Content-Type'));
        $body = $response->getContent();
        foreach (['/', '/posts', '/downloads', '/contact', '/departments/cit', '/departments/cba', '/posts/post-14'] as $path) {
            $this->assertStringContainsString('<loc>'.rtrim((string) config('sic.site_url'), '/').$path.'</loc>', $body);
        }
        $this->assertStringContainsString('<lastmod>2026-01-14T00:00:00.000Z</lastmod>', $body);
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $body);
    }

    public function test_the_rss_feed_lists_the_twenty_newest_posts(): void
    {
        $response = $this->get('/rss.xml')->assertOk();
        $this->assertSame('application/rss+xml; charset=utf-8', $response->headers->get('Content-Type'));
        $body = $response->getContent();
        $this->assertStringContainsString('<title>San Isidro College — News, Events &amp; Announcements</title>', $body);
        $this->assertSame(14, substr_count($body, '<item>'));
        $this->assertStringContainsString('<pubDate>Wed, 14 Jan 2026 00:00:00 GMT</pubDate>', $body);
        $this->assertStringContainsString('<category>event</category>', $body);
    }

    public function test_robots_and_manifest(): void
    {
        $robots = $this->get('/robots.txt')->assertOk();
        $this->assertStringStartsWith("User-agent: *\nAllow: /\nDisallow: /admin\n", $robots->getContent());
        $this->assertStringContainsString('Disallow: /styleguide', $robots->getContent());
        $this->assertStringEndsWith('/sitemap.xml'."\n", $robots->getContent());

        $manifest = $this->get('/manifest.webmanifest')->assertOk();
        $this->assertSame('application/manifest+json; charset=utf-8', $manifest->headers->get('Content-Type'));
        $this->assertSame('San Isidro College', $manifest->json('name'));
        $this->assertSame('SIC', $manifest->json('short_name'));
    }

    public function test_the_home_and_department_html_carries_the_first_slide_without_js(): void
    {
        $citId = Department::query()->where('slug', 'cit')->value('id');
        $overlay = Overlay::serializeOverlay(Overlay::legacyLayers(['title' => 'Welcome to SIC', 'subtitle' => 'Ora et Labora', 'linkUrl' => '/posts']));
        $this->makeSlide('carousels/hero.webp', ['order' => 1, 'overlay' => $overlay]);
        $this->makeSlide('carousels/dept.webp', ['order' => 1, 'overlay' => $overlay, 'department_id' => $citId, 'show_on_main' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee('src="/storage/carousels/hero.webp"', false)
            ->assertSee('srcset="/storage/carousels/hero.w960.webp 960w, /storage/carousels/hero.webp 1920w"', false)
            ->assertSee('fetchpriority="high"', false)
            ->assertSee('Welcome to SIC')
            ->assertSee('<h1 class="sr-only">San Isidro College — Ora et Labora</h1>', false);

        $this->get('/departments/cit')
            ->assertOk()
            ->assertSee('src="/storage/carousels/dept.webp"', false)
            ->assertSee('Welcome to SIC')
            ->assertSee('aria-label="College of IT highlights"', false);
    }

    public function test_a_zero_search_filters_and_the_first_duplicate_parameter_wins(): void
    {
        $this->get('/posts?search=0')->assertOk()->assertSee('1 post matching your filters.')->assertSee('noindex, follow');
        $this->get('/posts?type=event&type=article')->assertOk()->assertSee('7 posts matching your filters.');
        $this->get('/posts?search[]=x')->assertOk()->assertSee('Showing 1–12 of 14 posts');
        $this->get('/downloads?directory=0')->assertOk()->assertSee('No files found');
    }
}
