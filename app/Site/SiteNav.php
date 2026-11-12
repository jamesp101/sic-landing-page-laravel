<?php

namespace App\Site;

/**
 * The public site's navigation: turns the stored Navigation links and the Departments into the header and
 * footer items, and owns the default menu (the header fallback and the admin's "Use the default menu").
 *
 * Navigation link view: ['id' => int, 'label' => string, 'url' => string, 'children' => list<link view>].
 * Nav item: ['label' => string, 'href' => string] or ['label' => string, 'children' => list<['label','href']>].
 */
final class SiteNav
{
    /** Link URL that the header turns into a dropdown of every Department (also the home page section id). */
    public const DEPARTMENTS_MENU_URL = '#departments';

    /** The links "Use the default menu" creates, in order; the header shows the same menu while none are stored. */
    public const DEFAULT_NAV_LINKS = [
        ['label' => 'Home', 'url' => '/'],
        ['label' => 'Departments', 'url' => self::DEPARTMENTS_MENU_URL],
        ['label' => 'News & Events', 'url' => '/posts'],
        ['label' => 'Downloads', 'url' => '/downloads'],
        ['label' => 'Contact', 'url' => '/contact'],
    ];

    /** Pages that belong in the sitemap. */
    public const SITEMAP_PAGES = ['/', '/posts', '/downloads', '/contact'];

    /** Internal paths that exist besides Departments and Posts (valid link targets). */
    public const STATIC_PATHS = ['/', '/posts', '/downloads', '/contact', '/login', '/rss.xml', '/sitemap.xml'];

    /** Public page of a Department. */
    public static function departmentHref(string $slug): string
    {
        return "/departments/{$slug}";
    }

    /** Public page of a Post. */
    public static function postHref(string $slug): string
    {
        return "/posts/{$slug}";
    }

    /**
     * Header items: stored links when any exist, otherwise the default menu.
     *
     * @param  list<array<string, mixed>>  $navLinks
     * @param  list<array{name: string, slug: string}>  $departments
     * @return list<array<string, mixed>>
     */
    public static function headerItems(array $navLinks, array $departments): array
    {
        $departmentLinks = array_map(
            fn (array $d) => ['label' => $d['name'], 'href' => self::departmentHref($d['slug'])],
            array_values($departments),
        );

        if (count($navLinks) > 0) {
            return array_map(function (array $link) use ($departmentLinks): array {
                if ($link['url'] === self::DEPARTMENTS_MENU_URL) {
                    return ['label' => $link['label'], 'children' => $departmentLinks];
                }
                if (count($link['children']) > 0) {
                    return [
                        'label' => $link['label'],
                        'children' => array_map(fn (array $c) => ['label' => $c['label'], 'href' => $c['url']], array_values($link['children'])),
                    ];
                }

                return ['label' => $link['label'], 'href' => $link['url']];
            }, array_values($navLinks));
        }

        $items = [];
        foreach (self::DEFAULT_NAV_LINKS as $link) {
            if ($link['url'] !== self::DEPARTMENTS_MENU_URL) {
                $items[] = ['label' => $link['label'], 'href' => $link['url']];
            } elseif (count($departmentLinks) > 0) {
                $items[] = ['label' => $link['label'], 'children' => $departmentLinks];
            }
        }

        return $items;
    }

    /**
     * Footer columns: every Department (a link to the home page section while there are none) and the quick links.
     *
     * @param  list<array{name: string, slug: string}>  $departments
     * @return array{departments: list<array{label: string, href: string}>, quickLinks: list<array{label: string, href: string}>}
     */
    public static function footerItems(array $departments): array
    {
        return [
            'departments' => count($departments) > 0
                ? array_map(fn (array $d) => ['label' => $d['name'], 'href' => self::departmentHref($d['slug'])], array_values($departments))
                : [['label' => 'Academic programs', 'href' => '/'.self::DEPARTMENTS_MENU_URL]],
            'quickLinks' => [
                ['label' => 'News & Events', 'href' => '/posts'],
                ['label' => 'Downloads', 'href' => '/downloads'],
                ['label' => 'Contact & Admissions', 'href' => '/contact'],
                ['label' => 'RSS feed', 'href' => '/rss.xml'],
            ],
        ];
    }
}
