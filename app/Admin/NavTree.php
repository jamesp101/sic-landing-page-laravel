<?php

namespace App\Admin;

use App\Carousel\JsText;
use App\Models\NavigationLink;
use App\Site\SiteNav;

/**
 * Navigation tree rules and assembly (shared by the admin page and the actions; `stored()` is the only query).
 * Rule: top-level links may have children; a link that has children can never become a child itself.
 *
 * A node is `['id' => int, 'label' => string, 'url' => string, 'isActive' => bool, 'order' => int, 'children' => list<node>]`;
 * a reorder item is `['id' => int, 'parentId' => ?int, 'order' => int]`. The client-only moves (indent, drag, undo)
 * live in `resources/js/lib/nav-tree.ts`.
 */
final class NavTree
{
    public const TWO_LEVEL_ERROR = 'Navigation is two levels deep: only top-level links can have sub-links.';

    /** The one message for a rejected navigation URL (also used by the `navlinks` action's validation). */
    public const URL_ERROR = 'Use a path such as /posts or a full https:// URL.';

    private const WHITESPACE = '/[\s\x{feff}]/u';

    /** The stored Navigation links as nodes: top-level links in order, each with its children. @return list<array> */
    public static function stored(): array
    {
        $all = NavigationLink::query()->orderBy('order')->orderBy('id')->get();
        $toNode = fn (NavigationLink $link): array => [
            'id' => $link->id,
            'label' => $link->label,
            'url' => $link->url,
            'isActive' => $link->is_active,
            'order' => $link->order,
            'children' => [],
        ];

        return $all->whereNull('parent_id')->map(fn (NavigationLink $top): array => [
            ...$toNode($top),
            'children' => $all->where('parent_id', $top->id)->map($toNode)->values()->all(),
        ])->values()->all();
    }

    /** Every URL in a tree of nodes (top-level links and their children). @return list<string> */
    public static function urls(array $tree): array
    {
        $urls = [];
        foreach ($tree as $node) {
            $urls[] = $node['url'];
            foreach ($node['children'] as $child) {
                $urls[] = $child['url'];
            }
        }

        return $urls;
    }

    /** The links every site has; the editor offers them as suggestions and treats them as known paths. */
    public const PAGE_SUGGESTIONS = [
        ['group' => 'Pages', 'label' => 'Home', 'url' => '/'],
        ['group' => 'Pages', 'label' => 'News & Events', 'url' => '/posts'],
        ['group' => 'Pages', 'label' => 'Articles', 'url' => '/posts?type=article'],
        ['group' => 'Pages', 'label' => 'Events', 'url' => '/posts?type=event'],
        ['group' => 'Pages', 'label' => 'Announcements', 'url' => '/posts?type=announcement'],
        ['group' => 'Pages', 'label' => 'Downloads', 'url' => '/downloads'],
        ['group' => 'Pages', 'label' => 'Contact', 'url' => '/contact'],
    ];

    public const KIND_LABEL = [
        'page' => 'Page',
        'department' => 'Department',
        'post' => 'Post',
        'departments-menu' => 'Departments menu (auto)',
        'anchor' => 'Anchor',
        'external' => 'External',
        'email' => 'Email',
        'phone' => 'Phone',
    ];

    /** Accepts site paths, anchors and http(s)/mailto/tel URLs; rejects javascript:, data: and other schemes. */
    public static function navUrlError(string $raw): ?string
    {
        $url = JsText::trim($raw);
        if ($url === '') {
            return 'The URL is required.';
        }
        if (preg_match(self::WHITESPACE, $url) === 1) {
            return 'The URL must not contain spaces.';
        }
        if (str_starts_with($url, '//')) {
            return self::URL_ERROR;
        }
        if ($url[0] === '/' || $url[0] === '#') {
            return null;
        }
        if (preg_match('/^(https?:\/\/|mailto:|tel:)\S+\z/iu', $url) === 1) {
            return null;
        }

        return self::URL_ERROR;
    }

    /**
     * Validates a reorder request against the parent map currently stored (`$existing`: `['id', 'parentId']` rows).
     * Returns an error message, or null when the resulting tree is valid.
     */
    public static function reorderError(array $items, array $existing): ?string
    {
        $known = array_flip(array_column($existing, 'id'));
        $seen = [];
        foreach ($items as $item) {
            if (isset($seen[$item['id']])) {
                return 'A link appears more than once.';
            }
            $seen[$item['id']] = true;
            if (! isset($known[$item['id']])) {
                return 'One of the links no longer exists.';
            }
            if ($item['parentId'] === $item['id']) {
                return 'A link cannot be its own parent.';
            }
        }
        $finalParent = [];
        foreach ($existing as $row) {
            $finalParent[$row['id']] = $row['parentId'];
        }
        foreach ($items as $item) {
            $finalParent[$item['id']] = $item['parentId'];
        }
        foreach ($finalParent as $id => $parentId) {
            if ($parentId === null) {
                continue;
            }
            if (! isset($known[$parentId])) {
                return 'The selected parent link does not exist.';
            }
            if ($finalParent[$parentId] !== null) {
                return $id === $parentId ? 'A link cannot be its own parent.' : self::TWO_LEVEL_ERROR;
            }
        }

        return null;
    }

    /** Detects what a navigation URL points at. Returns null for an empty or unrecognised URL. */
    public static function classifyUrl(string $raw): ?string
    {
        $url = JsText::trim($raw);
        if ($url === '') {
            return null;
        }
        if ($url === SiteNav::DEPARTMENTS_MENU_URL) {
            return 'departments-menu';
        }
        if ($url[0] === '#') {
            return 'anchor';
        }
        if (preg_match('/^mailto:/i', $url) === 1) {
            return 'email';
        }
        if (preg_match('/^tel:/i', $url) === 1) {
            return 'phone';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return 'external';
        }
        if ($url[0] === '/' && ! str_starts_with($url, '//')) {
            $path = self::internalPath($url);
            if (preg_match('#^/departments/[^/]+\z#', $path) === 1) {
                return 'department';
            }
            if (preg_match('#^/posts/[^/]+\z#', $path) === 1) {
                return 'post';
            }

            return 'page';
        }

        return null;
    }

    /** Path of an internal URL without query or hash and without a trailing slash (except the root). */
    public static function internalPath(string $url): string
    {
        $path = preg_split('/[?#]/', JsText::trim($url), 2)[0];
        $decoded = self::decodeUri($path);

        return strlen($decoded) > 1 ? (rtrim($decoded, '/') ?: '/') : $decoded;
    }

    /** Slugs of the Posts that internal `/posts/<slug>` URLs point at, once each. @return list<string> */
    public static function postSlugsLinked(iterable $urls): array
    {
        $slugs = [];
        foreach ($urls as $url) {
            $trimmed = JsText::trim($url);
            if ($trimmed === '' || $trimmed[0] !== '/' || str_starts_with($trimmed, '//')) {
                continue;
            }
            if (preg_match('#^/posts/([^/]+)\z#', self::internalPath($trimmed), $match) === 1) {
                $slugs[$match[1]] = true;
            }
        }

        return array_map('strval', array_keys($slugs));
    }

    /** True when the public header ignores this link's own URL (it opens a dropdown instead). */
    public static function opensDropdown(array $node, bool $isTopLevel): bool
    {
        return $isTopLevel && (JsText::trim($node['url']) === SiteNav::DEPARTMENTS_MENU_URL || $node['children'] !== []);
    }

    /**
     * Warnings per link id (`['kind' => 'internal-missing'|'duplicate', 'message' => string]` lists). `internal-missing`:
     * an internal path that matches no known page, department or post (`/storage/*` is always allowed). `duplicate`:
     * two visible links share the same URL at the same level. Links whose URL is not used (dropdown parents) are
     * skipped. Warnings never block saving.
     *
     * @param  iterable<string>  $knownPaths
     * @return array<int, list<array{kind: string, message: string}>>
     */
    public static function navWarnings(array $tree, iterable $knownPaths): array
    {
        $known = [];
        foreach ($knownPaths as $path) {
            $known[self::internalPath($path)] = true;
        }
        $result = [];
        $check = function (array $nodes, bool $isTopLevel) use (&$result, $known): void {
            $seen = [];
            foreach ($nodes as $node) {
                if (self::opensDropdown($node, $isTopLevel)) {
                    continue;
                }
                $url = JsText::trim($node['url']);
                if ($url !== '' && $url[0] === '/' && ! str_starts_with($url, '//')) {
                    $path = self::internalPath($url);
                    if (! isset($known[$path]) && ! str_starts_with($path, '/storage/')) {
                        $result[$node['id']][] = [
                            'kind' => 'internal-missing',
                            'message' => "No page, department or post exists at {$path}. Visitors would see a “not found” page.",
                        ];
                    }
                }
                if ($node['isActive']) {
                    $seen[$url][] = $node;
                }
            }
            foreach ($seen as $url => $group) {
                if (count($group) < 2) {
                    continue;
                }
                foreach ($group as $node) {
                    $result[$node['id']][] = [
                        'kind' => 'duplicate',
                        'message' => (count($group) - 1 === 1 ? 'Another visible link' : 'Other visible links')." at the same level also point to {$url}.",
                    ];
                }
            }
        };
        $check($tree, true);
        foreach ($tree as $top) {
            $check($top['children'], false);
        }

        return $result;
    }

    /** JavaScript `decodeURI`: percent sequences decode except those of reserved characters; malformed input stays raw. */
    private static function decodeUri(string $path): string
    {
        $failed = false;
        $decoded = preg_replace_callback('/(?:%[0-9A-Fa-f]{2})+/', function (array $match) use (&$failed): string {
            $text = rawurldecode($match[0]);
            if (! mb_check_encoding($text, 'UTF-8')) {
                $failed = true;

                return $match[0];
            }

            return preg_replace_callback('/[;\/?:@&=+$,#]/', fn (array $c): string => '%'.strtoupper(bin2hex($c[0])), $text) ?? $text;
        }, $path);

        return $failed || $decoded === null ? $path : $decoded;
    }
}
