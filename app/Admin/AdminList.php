<?php

namespace App\Admin;

use App\Models\User;
use App\Support\Pagination;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one query behind every paginated admin list. A page declares its sortable fields once (`spec`) and
 * calls `runList` with the request URL; it gets back a ListState and never builds an ordering, a restriction
 * or a link itself. The actor's Admin scope always applies (`AdminScope::listWhere`), whatever the search,
 * filters, sort or page say.
 *
 * Constraints, orderings and filters are closures over an Eloquent builder (see `apply`).
 */
final class AdminList
{
    public const LIST_PER_PAGE = 25;

    /**
     * A sortable field: `$orderBy(string $dir): Closure(Builder)` applies the ordering; `$defaultDir` is the
     * direction used when the list is first sorted by it.
     *
     * @param  Closure(string): Closure  $orderBy
     * @return array{orderBy: Closure, defaultDir: string}
     */
    public static function sortBy(Closure $orderBy, string $defaultDir = 'asc'): array
    {
        return ['orderBy' => $orderBy, 'defaultDir' => $defaultDir];
    }

    /** `sortBy` for one column (`'created_at'`). */
    public static function sortColumn(string $column, string $defaultDir = 'asc'): array
    {
        return self::sortBy(fn (string $dir) => fn (Builder $q) => $q->orderBy($column, $dir), $defaultDir);
    }

    /** Ordering closure for `spec['tiebreak']`. */
    public static function orderColumn(string $column, string $dir = 'asc'): Closure
    {
        return fn (Builder $q) => $q->orderBy($column, $dir);
    }

    /**
     * A filter a page declares once: the query parameter is its key in `filters`, `$parse` turns the raw
     * value (?string) into a typed one (null when absent or invalid, which is then ignored), and `$where`
     * narrows the query for a parsed value by returning a closure over the builder (false narrows nothing).
     *
     * @return array{parse: Closure, where: Closure}
     */
    public static function listFilter(Closure $parse, Closure $where): array
    {
        return ['parse' => $parse, 'where' => $where];
    }

    /** `yes` → true, `no` → false, anything else → null (no filter). */
    public static function parseYesNo(?string $raw): ?bool
    {
        return $raw === 'yes' ? true : ($raw === 'no' ? false : null);
    }

    /**
     * Applies the `fetch` arguments (`where`, `orderBy`, `skip`, `take`) to a builder.
     *
     * @param  array{where: Closure, orderBy: list<Closure>, skip: int, take: int}  $args
     */
    public static function apply(Builder $query, array $args): Builder
    {
        $query->where($args['where']);
        foreach ($args['orderBy'] as $order) {
            $order($query);
        }

        return $query->offset($args['skip'])->limit($args['take']);
    }

    /**
     * @param  array{
     *     url: string,
     *     actor: User,
     *     scope: array,
     *     spec: array{fields: array<string, array{orderBy: Closure, defaultDir: string}>, defaultSort: string, tiebreak: Closure, search?: list<string>, perPage?: int},
     *     filters?: array<string, array{parse: Closure, where: Closure}>,
     *     restriction?: Closure,
     *     count: Closure(Closure): int,
     *     fetch: Closure(array): iterable
     * }  $input  `url`: request path with query (`$request->getRequestUri()`) or a full URL. `restriction`
     *            replaces the Admin scope for lists that are not department-owned rows (the departments list is
     *            restricted by id, the users list is superadmin-only); it is still ANDed, so search cannot widen it.
     */
    public static function runList(array $input): ListState
    {
        ['actor' => $actor, 'scope' => $scope, 'spec' => $spec, 'count' => $count, 'fetch' => $fetch] = $input;
        $declared = $input['filters'] ?? [];
        $parts = parse_url($input['url']);
        $path = $parts['path'] ?? '/';
        $params = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[] = [urldecode($key), urldecode($value)];
        }
        $get = function (string $name) use ($params): ?string {
            foreach ($params as [$key, $value]) {
                if ($key === $name) {
                    return $value;
                }
            }

            return null;
        };
        $perPage = $spec['perPage'] ?? self::LIST_PER_PAGE;

        $q = trim($get('q') ?? '');
        $requested = $get('sort') ?? '';
        $sort = array_key_exists($requested, $spec['fields']) ? $requested : $spec['defaultSort'];
        $field = $spec['fields'][$sort];
        $requestedDir = $get('dir');
        $dir = in_array($requestedDir, ['asc', 'desc'], true) ? $requestedDir : $field['defaultDir'];

        $values = [];
        $conditions = [];
        foreach ($declared as $name => $filter) {
            $value = $filter['parse']($get((string) $name));
            $values[$name] = $value;
            if ($value !== null) {
                $conditions[] = $filter['where']($value);
            }
        }
        $search = $q !== '' && ! empty($spec['search']) ? Search::anyOf($spec['search'], $q) : null;
        $extra = [$search, ...$conditions];

        $where = isset($input['restriction'])
            ? self::restricted($input['restriction'], $extra)
            : AdminScope::listWhere($actor, $scope, $extra);

        $filtered = $q !== '' || array_filter($conditions, fn ($c) => $c instanceof Closure) !== [];
        $total = $count($where);
        $pageCount = max(1, (int) ceil($total / $perPage));
        // Past the end (e.g. after deleting the last row of the last page): show the last page instead.
        $page = min(Pagination::parsePage($get('page')), $pageCount);
        $rows = $fetch([
            'where' => $where,
            'orderBy' => [$field['orderBy']($dir), $spec['tiebreak']],
            'skip' => ($page - 1) * $perPage,
            'take' => $perPage,
        ]);

        return new ListState(
            rows: $rows,
            total: $total,
            page: $page,
            pageCount: $pageCount,
            perPage: $perPage,
            q: $q,
            filtered: $filtered,
            sort: $sort,
            dir: $dir,
            values: $values,
            path: $path,
            params: $params,
            filterNames: array_map('strval', array_keys($declared)),
            fields: $spec['fields'],
        );
    }

    private static function restricted(Closure $restriction, array $extra): Closure
    {
        return static function (Builder $q) use ($restriction, $extra): void {
            $q->where($restriction);
            foreach ($extra as $filter) {
                if ($filter instanceof Closure) {
                    $q->where($filter);
                }
            }
        };
    }
}
