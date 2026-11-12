<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Site\Queries;
use App\Site\Seo;
use App\Site\SiteNav;
use App\Support\Site;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** `/sitemap.xml`, `/rss.xml`, `/robots.txt`, `/manifest.webmanifest`. */
class FeedController extends Controller
{
    private const DISALLOW = [
        '/admin', '/dashboard', '/settings', '/styleguide', '/api/', '/login', '/forgot-password',
        '/reset-password', '/confirm-password', '/verify-email',
    ];

    public function sitemap(Request $request): Response
    {
        $origin = Seo::siteUrl(Seo::context($request));
        ['departments' => $departments, 'posts' => $posts] = Queries::getSitemapData();
        $latestPost = null;
        foreach ($posts as $post) {
            if ($latestPost === null || $post['updatedAt'] > $latestPost) {
                $latestPost = $post['updatedAt'];
            }
        }

        $entry = function (string $path, ?CarbonInterface $lastmod = null, ?array $image = null) use ($origin): string {
            return implode("\n", array_filter([
                '  <url>',
                '    <loc>'.Seo::escapeXml(Seo::absoluteUrl($path, $origin)).'</loc>',
                $lastmod ? '    <lastmod>'.Seo::iso($lastmod).'</lastmod>' : '',
                $image
                    ? '    <image:image><image:loc>'.Seo::escapeXml(Seo::absoluteUrl($image['url'], $origin)).'</image:loc><image:title>'.Seo::escapeXml($image['title']).'</image:title></image:image>'
                    : '',
                '  </url>',
            ]));
        };

        $body = implode("\n", [
            ...array_map(fn (string $path) => $entry($path, $path === '/' || $path === '/posts' ? $latestPost : null), SiteNav::SITEMAP_PAGES),
            ...array_map(fn (array $d) => $entry(SiteNav::departmentHref($d['slug']), $d['updatedAt']), $departments),
            ...array_map(fn (array $p) => $entry(SiteNav::postHref($p['slug']), $p['updatedAt'], $p['imageUrl'] ? ['url' => $p['imageUrl'], 'title' => $p['title']] : null), $posts),
        ]);

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
{$body}
</urlset>

XML;

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function rss(Request $request): Response
    {
        $origin = Seo::siteUrl(Seo::context($request));
        $posts = Queries::listPosts(['sort' => 'newest'], 20)['items'];
        $feedUrl = Seo::absoluteUrl('/rss.xml', $origin);

        $items = implode("\n", array_map(function (array $post) use ($origin): string {
            $link = Seo::escapeXml(Seo::absoluteUrl(SiteNav::postHref($post['slug']), $origin));

            return implode("\n", [
                '    <item>',
                '      <title>'.Seo::escapeXml($post['title']).'</title>',
                "      <link>{$link}</link>",
                "      <guid isPermaLink=\"true\">{$link}</guid>",
                '      <pubDate>'.$post['createdAt']->copy()->utc()->format('D, d M Y H:i:s \G\M\T').'</pubDate>',
                '      <category>'.Seo::escapeXml($post['type']).'</category>',
                '      <description>'.Seo::escapeXml($post['excerpt']).'</description>',
                '    </item>',
            ]);
        }, $posts));

        $title = Seo::escapeXml(Site::SITE['name'].' — News, Events & Announcements');
        $link = Seo::escapeXml(Seo::absoluteUrl('/posts', $origin));
        $description = Seo::escapeXml(Site::SITE['description']);
        $self = Seo::escapeXml($feedUrl);

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title>{$title}</title>
    <link>{$link}</link>
    <description>{$description}</description>
    <language>en-ph</language>
    <atom:link href="{$self}" rel="self" type="application/rss+xml" />
{$items}
  </channel>
</rss>

XML;

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8', 'Cache-Control' => 'public, max-age=900']);
    }

    public function robots(Request $request): Response
    {
        $origin = Seo::siteUrl(Seo::context($request));
        $body = implode("\n", [
            'User-agent: *',
            'Allow: /',
            ...array_map(fn (string $path) => "Disallow: {$path}", self::DISALLOW),
            '',
            'Sitemap: '.Seo::absoluteUrl('/sitemap.xml', $origin),
            '',
        ]);

        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function manifest(): Response
    {
        $manifest = [
            'name' => Site::SITE['name'],
            'short_name' => Site::SITE['shortName'],
            'description' => Site::SITE['description'],
            'lang' => Site::SITE['lang'],
            'start_url' => '/',
            'scope' => '/',
            'display' => 'browser',
            'background_color' => '#f2f7fe',
            'theme_color' => '#0a2e6e',
            'icons' => [
                ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/apple-touch-icon.png', 'sizes' => '180x180', 'type' => 'image/png'],
            ],
        ];

        return response(json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 200, [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
