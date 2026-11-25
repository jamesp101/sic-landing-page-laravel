<?php

namespace App\Site;

use App\Support\Site;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * Page SEO: canonical URLs, the single description clamp and every JSON-LD builder.
 *
 * `$context` is ['site' => ?string, 'url' => string]: the configured site origin and the absolute request URL
 * (`Seo::context($request)`). `$site` is an absolute site URL string. Page SEO is an array with the keys of the TS
 * `PageSeo`: title, description, canonical, image, imageAlt, type, noindex, follow, publishedTime, modifiedTime
 * (DateTimeInterface), jsonLd (one object or a list), breadcrumbs (list of ['label', 'href'?]).
 */
final class Seo
{
    /** Longest meta / Open Graph description. The one clamp every public description goes through. */
    public const META_DESCRIPTION_MAX = 160;

    /** @return array{site: ?string, url: string} */
    public static function context(Request $request): array
    {
        $site = (string) config('sic.site_url');

        return ['site' => $site !== '' ? $site : null, 'url' => $request->url()];
    }

    /** Where the app lives: the configured site, else the origin of the current request. Always ends in `/`. */
    public static function siteUrl(array $context): string
    {
        if (! empty($context['site'])) {
            return rtrim($context['site'], '/').'/';
        }
        $parts = parse_url($context['url']);

        return self::origin($parts).'/';
    }

    /** Absolute URL for a site-relative path (or pass-through for absolute URLs). */
    public static function absoluteUrl(string $path, string $site): string
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $path)) {
            return $path;
        }
        $parts = parse_url($site);
        if (str_starts_with($path, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$path;
        }
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return self::origin($parts).$path;
    }

    /** Absolute canonical URL of a page: its explicit `canonical`, else the request path (query-free). */
    public static function canonicalHref(?string $canonical, array $context): string
    {
        $path = $canonical ?? (parse_url($context['url'], PHP_URL_PATH) ?: '/');

        return self::absoluteUrl($path, self::siteUrl($context));
    }

    /** `Date.prototype.toISOString()`. */
    public static function iso(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Collapses whitespace and clamps to `META_DESCRIPTION_MAX`; `''` when there is nothing to say. */
    public static function metaDescription(?string $text): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $text ?? '') ?? '');
        if (mb_strlen($clean) <= self::META_DESCRIPTION_MAX) {
            return $clean;
        }

        return rtrim(mb_substr($clean, 0, self::META_DESCRIPTION_MAX - 1)).'…';
    }

    public static function escapeXml(string $value): string
    {
        return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], $value);
    }

    /** The college itself; used on the home page and referenced elsewhere via @id. */
    public static function collegeOrganization(string $site): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollegeOrUniversity',
            '@id' => self::orgId($site),
            'name' => Site::SITE['name'],
            'alternateName' => Site::SITE['shortName'],
            'url' => self::absoluteUrl('/', $site),
            'logo' => ['@type' => 'ImageObject', 'url' => self::absoluteUrl(Site::SITE['logo'], $site)],
            'image' => self::absoluteUrl(Site::SITE['defaultOgImage'], $site),
            'description' => Site::SITE['description'],
            'address' => self::postalAddress(),
            'telephone' => Site::SITE['telephoneE164'],
            'email' => Site::SITE['email'],
            'foundingDate' => Site::SITE['foundingDate'],
            'founder' => ['@type' => 'Person', 'name' => Site::SITE['founder']],
            'sameAs' => array_values(Site::SITE['sameAs']),
        ];
    }

    public static function webSite(string $site): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => self::absoluteUrl('/', $site).'#website',
            'url' => self::absoluteUrl('/', $site),
            'name' => Site::SITE['name'],
            'inLanguage' => 'en-PH',
            'publisher' => ['@id' => self::orgId($site)],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => self::absoluteUrl('/posts', $site).'?search={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * `$current` is the absolute URL used for the final crumb when it has no href.
     *
     * @param  list<array{label: string, href?: ?string}>  $crumbs
     */
    public static function breadcrumbList(array $crumbs, string $site, string $current): array
    {
        $items = [];
        foreach (array_values($crumbs) as $index => $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['label'],
                'item' => ! empty($crumb['href']) ? self::absoluteUrl($crumb['href'], $site) : $current,
            ];
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /** @param list<array<string, mixed>> $posts */
    public static function postsItemList(array $posts, string $site, string $name): array
    {
        $items = [];
        foreach (array_values($posts) as $index => $post) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'url' => self::absoluteUrl(SiteNav::postHref($post['slug']), $site),
                'name' => $post['title'],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $name,
            'numberOfItems' => count($posts),
            'itemListElement' => $items,
        ];
    }

    /** Event for event posts that have a start date; NewsArticle otherwise. */
    public static function postJsonLd(array $post, string $site, string $canonical): array
    {
        $image = $post['imageUrl'] ? [self::absoluteUrl($post['imageUrl'], $site)] : null;

        if ($post['type'] === 'event' && $post['startDate']) {
            return [
                '@context' => 'https://schema.org',
                '@type' => 'Event',
                'name' => $post['title'],
                'description' => $post['excerpt'],
                'url' => $canonical,
                'startDate' => self::iso($post['startDate']),
                ...($post['endDate'] ? ['endDate' => self::iso($post['endDate'])] : []),
                'eventStatus' => 'https://schema.org/EventScheduled',
                'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                'location' => ['@type' => 'Place', 'name' => Site::SITE['name'], 'address' => self::postalAddress()],
                'organizer' => ['@id' => self::orgId($site)],
                ...($image ? ['image' => $image] : []),
            ];
        }

        $title = $post['title'];

        return [
            '@context' => 'https://schema.org',
            '@type' => 'NewsArticle',
            'headline' => mb_strlen($title) > 110 ? rtrim(mb_substr($title, 0, 107)).'…' : $title,
            'description' => $post['excerpt'],
            ...($image ? ['image' => $image] : []),
            'datePublished' => self::iso($post['createdAt']),
            'dateModified' => self::iso($post['updatedAt']),
            'articleSection' => $post['type'] === 'announcement' ? 'Announcements' : 'News',
            'inLanguage' => 'en-PH',
            // A Post that hides its author is attributed to the college.
            'author' => ! empty($post['authorName']) ? ['@type' => 'Person', 'name' => $post['authorName']] : ['@id' => self::orgId($site)],
            'publisher' => [
                '@type' => 'Organization',
                '@id' => self::orgId($site),
                'name' => Site::SITE['name'],
                'logo' => ['@type' => 'ImageObject', 'url' => self::absoluteUrl(Site::SITE['logo'], $site)],
            ],
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
        ];
    }

    /** A department as a sub-organisation of the college, with its staff as Person entries. */
    public static function departmentJsonLd(array $department, array $members, string $site, string $canonical): array
    {
        $people = array_map(fn (array $member) => [
            '@type' => 'Person',
            'name' => $member['name'],
            'jobTitle' => $member['role'],
            ...($member['imageUrl'] ? ['image' => self::absoluteUrl($member['imageUrl'], $site)] : []),
        ], self::flattenMembers($members));

        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollegeOrUniversity',
            '@id' => $canonical.'#department',
            'name' => $department['name'],
            'url' => $canonical,
            ...($department['description'] ? ['description' => $department['description']] : []),
            ...($department['logoUrl'] ? ['logo' => self::absoluteUrl($department['logoUrl'], $site)] : []),
            'parentOrganization' => ['@id' => self::orgId($site), '@type' => 'CollegeOrUniversity', 'name' => Site::SITE['name']],
            'address' => self::postalAddress(),
            ...($people ? ['employee' => $people] : []),
        ];
    }

    public static function postPageSeo(array $post, string $site): array
    {
        $canonical = SiteNav::postHref($post['slug']);

        return [
            'title' => $post['title'],
            'description' => $post['excerpt'] !== '' ? $post['excerpt'] : $post['title'],
            'canonical' => $canonical,
            'type' => 'article',
            // A Hidden post opens by its URL only; search engines are asked to leave it out too.
            'noindex' => $post['isHidden'] ?? false,
            'image' => $post['imageUrl'],
            'imageAlt' => $post['title'],
            'publishedTime' => $post['createdAt'],
            'modifiedTime' => $post['updatedAt'],
            'breadcrumbs' => [['label' => 'Home', 'href' => '/'], ['label' => 'News & Events', 'href' => '/posts'], ['label' => $post['title']]],
            'jsonLd' => self::postJsonLd($post, $site, self::absoluteUrl($canonical, $site)),
        ];
    }

    public static function departmentPageSeo(array $department, array $members, ?string $fallbackImage, string $site): array
    {
        $canonical = SiteNav::departmentHref($department['slug']);
        $description = trim((string) $department['description']);

        return [
            'title' => $department['name'],
            'description' => $description !== '' ? $description : $department['name'].' at '.Site::SITE['name'].', Malaybalay City, Bukidnon.',
            'canonical' => $canonical,
            'image' => $department['logoUrl'] ?? $fallbackImage,
            'imageAlt' => $department['name'],
            'modifiedTime' => $department['updatedAt'],
            'breadcrumbs' => [['label' => 'Home', 'href' => '/'], ['label' => $department['name']]],
            'jsonLd' => self::departmentJsonLd($department, $members, $site, self::absoluteUrl($canonical, $site)),
        ];
    }

    /** `/posts` listing: filtered views are `noindex, follow`; the canonical keeps only the page number. */
    public static function postsListSeo(array $posts, array $filters, int $page, string $site): array
    {
        $title = $page > 1 ? "News, Events & Announcements (page {$page})" : 'News, Events & Announcements';

        return [
            'title' => $title,
            'description' => 'Latest news, campus events and official announcements from San Isidro College in Malaybalay City, Bukidnon.',
            'canonical' => PostFilters::postsHref([], $page),
            'noindex' => PostFilters::hasPostFilters($filters),
            'follow' => true,
            'breadcrumbs' => [['label' => 'Home', 'href' => '/'], ['label' => 'News & Events']],
            'jsonLd' => self::postsItemList($posts, $site, $title),
        ];
    }

    public static function homePageSeo(string $site): array
    {
        return ['description' => Site::SITE['description'], 'canonical' => '/', 'jsonLd' => self::collegeOrganization($site)];
    }

    /** One JSON-LD object as the body of a `<script type="application/ld+json">`: `<` escaped so content cannot close it. */
    public static function jsonLdScript(array $item): string
    {
        return str_replace('<', '\\u003c', json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function orgId(string $site): string
    {
        return self::absoluteUrl('/', $site).'#organization';
    }

    private static function postalAddress(): array
    {
        return ['@type' => 'PostalAddress', ...Site::SITE['address']];
    }

    private static function flattenMembers(array $nodes): array
    {
        $flat = [];
        foreach ($nodes as $node) {
            $flat[] = $node;
            array_push($flat, ...self::flattenMembers($node['children']));
        }

        return $flat;
    }

    private static function origin(array $parts): string
    {
        return ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? 'localhost').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
