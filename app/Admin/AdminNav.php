<?php

namespace App\Admin;

use App\Models\User;
use App\Support\Authz;

/**
 * The admin sidebar menu: the one list of its links, which ones the User sees in their Admin scope, and which one is
 * the current page. The server-rendered shell (`x-admin.shell`) and the `admin/AdminShell` island both print it.
 */
final class AdminNav
{
    /**
     * @param  array{kind: string, id?: int}  $scope
     * @return list<array{title: string, href: string, icon: string, active: bool}>
     */
    public static function items(User $user, array $scope, string $currentPath): array
    {
        $items = [
            ['title' => 'Dashboard', 'href' => '/dashboard', 'icon' => 'layout-grid'],
            ['title' => 'Posts', 'href' => '/admin/posts', 'icon' => 'file-text'],
            ['title' => 'Downloads', 'href' => '/admin/downloads', 'icon' => 'download'],
            ['title' => 'Hero sections', 'href' => '/admin/hero-sections', 'icon' => 'sparkles'],
            ['title' => 'Departments', 'href' => '/admin/departments', 'icon' => 'school'],
            ...($scope['kind'] === 'department'
                ? [['title' => 'Org chart', 'href' => "/admin/departments/{$scope['id']}/members", 'icon' => 'network']]
                : []),
            ['title' => 'Carousels', 'href' => '/admin/carousels', 'icon' => 'image'],
            ...(Authz::isSuperAdmin($user)
                ? [
                    ['title' => 'Navigation links', 'href' => '/admin/navlinks', 'icon' => 'link-2'],
                    ['title' => 'Users', 'href' => '/admin/users', 'icon' => 'users'],
                    ['title' => 'Landing settings', 'href' => '/admin/settings/landing', 'icon' => 'settings'],
                ]
                : []),
            ['title' => 'View site', 'href' => '/', 'icon' => 'house'],
        ];
        $hrefs = array_column($items, 'href');

        return array_map(fn (array $item): array => [
            ...$item,
            'active' => self::isActive($item['href'], $hrefs, $currentPath),
        ], $items);
    }

    /**
     * The link matches the path (itself or a page below it) and no longer link does: the most specific match wins,
     * e.g. Org chart over Departments.
     *
     * @param  list<string>  $hrefs
     */
    private static function isActive(string $href, array $hrefs, string $currentPath): bool
    {
        $matches = static fn (string $h): bool => $h === '/'
            ? $currentPath === '/'
            : $currentPath === $h || str_starts_with($currentPath, $h.'/');

        if (! $matches($href)) {
            return false;
        }
        foreach ($hrefs as $other) {
            if (strlen($other) > strlen($href) && $matches($other)) {
                return false;
            }
        }

        return true;
    }
}
