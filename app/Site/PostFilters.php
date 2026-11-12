<?php

namespace App\Site;

use App\Content\Js;

/**
 * Query-string rules shared by the public listings (`/posts`, `/downloads`): what a filter value may be, and the
 * listing URL for a set of filters.
 *
 * Post filters: ['type'?, 'departmentId'?, 'search'?, 'sort'?]; download filters: ['search'?, 'departmentId'?, 'directory'?].
 * Query parameters come as the request's `query()` array; non-string values count as absent.
 */
final class PostFilters
{
    public const POST_TYPES = ['article', 'event', 'announcement'];

    private const SEARCH_MAX = 100;

    private const DIRECTORY_MAX = 255;

    /** A `department_id` value: a positive integer, otherwise no filter. */
    public static function parseDepartmentId(?string $value): ?int
    {
        $text = $value === null ? '' : self::trim($value);
        if ($text === '' || ! ctype_digit($text) || (int) $text <= 0) {
            return null;
        }

        return (int) $text;
    }

    /** A `search` value: trimmed and capped, `''` when absent. */
    public static function parseSearch(?string $value): string
    {
        return $value === null ? '' : Js::head(self::trim($value), self::SEARCH_MAX);
    }

    /**
     * The filters of a `/posts` request; unknown or malformed values are dropped, `sort` always resolves.
     *
     * @param  array<string, mixed>  $params
     * @return array{type: ?string, departmentId: ?int, search: ?string, sort: string}
     */
    public static function parsePostFilters(array $params): array
    {
        $type = self::param($params, 'type');

        return [
            'type' => $type !== null && in_array($type, self::POST_TYPES, true) ? $type : null,
            'departmentId' => self::parseDepartmentId(self::param($params, 'department_id')),
            'search' => self::orNull(self::parseSearch(self::param($params, 'search'))),
            'sort' => self::param($params, 'sort') === 'oldest' ? 'oldest' : 'newest',
        ];
    }

    /**
     * Whether `$filters` narrow or reorder the default list.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function hasPostFilters(array $filters): bool
    {
        return self::set($filters['type'] ?? null) || self::set($filters['departmentId'] ?? null) || self::set($filters['search'] ?? null) || ($filters['sort'] ?? null) === 'oldest';
    }

    /**
     * `/posts` URL for `$filters` on `$page` (1-based); default values stay out of the query string.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function postsHref(array $filters, int $page = 1): string
    {
        $query = [];
        if (self::set($filters['type'] ?? null)) {
            $query['type'] = $filters['type'];
        }
        if (self::set($filters['departmentId'] ?? null)) {
            $query['department_id'] = (string) $filters['departmentId'];
        }
        if (self::set($filters['search'] ?? null)) {
            $query['search'] = $filters['search'];
        }
        if (($filters['sort'] ?? null) === 'oldest') {
            $query['sort'] = 'oldest';
        }
        if ($page > 1) {
            $query['page'] = (string) $page;
        }

        return '/posts'.self::queryString($query);
    }

    /**
     * The filters of a `/downloads` request; malformed values are dropped.
     *
     * @param  array<string, mixed>  $params
     * @return array{search: ?string, departmentId: ?int, directory: ?string}
     */
    public static function parseDownloadFilters(array $params): array
    {
        $directory = self::param($params, 'directory');

        return [
            'search' => self::orNull(self::parseSearch(self::param($params, 'search'))),
            'departmentId' => self::parseDepartmentId(self::param($params, 'department_id')),
            'directory' => $directory === null ? null : self::orNull(Js::head(self::trim($directory), self::DIRECTORY_MAX)),
        ];
    }

    /**
     * Whether `$filters` narrow the default `/downloads` list.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function hasDownloadFilters(array $filters): bool
    {
        return self::set($filters['search'] ?? null) || self::set($filters['departmentId'] ?? null) || self::set($filters['directory'] ?? null);
    }

    /**
     * `/downloads` URL for `$filters`; default values stay out of the query string.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function downloadsHref(array $filters): string
    {
        $query = [];
        if (self::set($filters['search'] ?? null)) {
            $query['search'] = $filters['search'];
        }
        if (self::set($filters['departmentId'] ?? null)) {
            $query['department_id'] = (string) $filters['departmentId'];
        }
        if (self::set($filters['directory'] ?? null)) {
            $query['directory'] = $filters['directory'];
        }

        return '/downloads'.self::queryString($query);
    }

    /**
     * Query parameters the way `URLSearchParams.get` sees them: the FIRST value of each name wins, array-style names
     * (`search[]`) are different names and so never match a plain one. Pass the raw query string.
     *
     * @return array<string, string>
     */
    public static function queryParams(?string $rawQuery): array
    {
        $params = [];
        foreach (explode('&', (string) $rawQuery) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[urldecode($name)] ??= urldecode($value);
        }

        return $params;
    }

    /** JS truthiness of a filter value: only null, `''` and `0` are unset (`"0"` is a real value). */
    private static function set(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== 0;
    }

    private static function orNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $params */
    private static function param(array $params, string $name): ?string
    {
        $value = $params[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /** Like JS `String.prototype.trim` (Unicode white space, BOM). */
    private static function trim(string $value): string
    {
        return preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $value) ?? $value;
    }

    /** `URLSearchParams.toString()` with a leading `?`; empty for no parameters. */
    private static function queryString(array $query): string
    {
        if ($query === []) {
            return '';
        }

        return '?'.str_replace('%2A', '*', http_build_query($query, '', '&', PHP_QUERY_RFC1738));
    }
}
