<?php

namespace App\Admin;

/**
 * Result of `AdminList::runList`: the rows of one page, the totals and the links a list page renders.
 * Pages never build an ordering, a restriction, a page clamp or a link themselves.
 */
final class ListState
{
    /**
     * @param  iterable<mixed>  $rows
     * @param  array<string, mixed>  $values  parsed value of each declared filter (null when absent or invalid), by query parameter
     * @param  list<array{0: string, 1: string}>  $params  the request's query pairs
     * @param  list<string>  $filterNames
     * @param  array<string, array{defaultDir: string}>  $fields
     */
    public function __construct(
        public readonly iterable $rows,
        public readonly int $total,
        /** 1-based; an out-of-range request shows the last page. */
        public readonly int $page,
        public readonly int $pageCount,
        public readonly int $perPage,
        /** Trimmed `?q=` ('' when absent). */
        public readonly string $q,
        /** A search or a filter narrows the list (sort, direction and page do not count). */
        public readonly bool $filtered,
        public readonly string $sort,
        public readonly string $dir,
        public readonly array $values,
        private readonly string $path,
        private readonly array $params,
        private readonly array $filterNames,
        private readonly array $fields,
    ) {}

    /** The list without search, filters and page; sort and direction stay, so a reset does not reorder it. */
    public function resetHref(): string
    {
        return $this->href(['q' => null, 'page' => null] + array_fill_keys($this->filterNames, null));
    }

    /** Same list with `$changes` applied to its parameters (null removes one); back on page 1 unless `page` is set. */
    public function href(array $changes): string
    {
        $pairs = $this->params;
        if (! array_key_exists('page', $changes)) {
            $pairs = array_values(array_filter($pairs, fn ($p) => $p[0] !== 'page'));
        }
        foreach ($changes as $key => $value) {
            $key = (string) $key;
            if ($value === null) {
                $pairs = array_values(array_filter($pairs, fn ($p) => $p[0] !== $key));

                continue;
            }
            $replaced = false;
            $next = [];
            foreach ($pairs as $pair) {
                if ($pair[0] !== $key) {
                    $next[] = $pair;
                } elseif (! $replaced) {
                    $next[] = [$key, (string) $value];
                    $replaced = true;
                }
            }
            if (! $replaced) {
                $next[] = [$key, (string) $value];
            }
            $pairs = $next;
        }
        $query = implode('&', array_map(fn ($p) => urlencode($p[0]).'='.urlencode($p[1]), $pairs));

        return $this->path.($query === '' ? '' : '?'.$query);
    }

    /** Link to `$page` keeping the other parameters; page 1 is left out of the URL. */
    public function pageHref(int $page): string
    {
        return $this->href(['page' => $page > 1 ? (string) $page : null]);
    }

    /** Link that sorts by `$field` (toggles the direction when it is the current sort); resets to page 1. */
    public function sortHref(string $field): string
    {
        $next = $this->sort === $field
            ? ($this->dir === 'asc' ? 'desc' : 'asc')
            : $this->fields[$field]['defaultDir'];

        return $this->href(['sort' => $field, 'dir' => $next]);
    }

    /** `aria-sort` value of `$field`'s header; null when the list is not sorted by it. */
    public function ariaSort(string $field): ?string
    {
        return $this->sort === $field ? ($this->dir === 'asc' ? 'ascending' : 'descending') : null;
    }
}
