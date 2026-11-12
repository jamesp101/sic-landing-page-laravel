<?php

namespace App\Admin;

/**
 * Pure server-side rules for the department org chart: `buildOrgTree` for the public chart, and `wouldCycle`,
 * `placementsError` and `reparentOnDelete` for the `members` actions. The editor's own moves live in
 * `resources/js/lib/org-tree.ts`.
 * Siblings are ordered by (`order`, `id`) exactly like the public chart; a delete renumbers the affected sibling
 * lists 1..n and reports only the rows that actually changed.
 *
 * A member is an array with at least `id`, `parentId` (?int) and `order`; the org-chart view model adds
 * `name`, `role` and `imageUrl`. A placement is `['id' => int, 'parentId' => ?int, 'order' => int]`.
 * A tree node is `['member' => array, 'children' => list<node>]`.
 */
final class OrgTree
{
    /** @param array{id: int, order: int} $a */
    private static function byOrder(array $a, array $b): int
    {
        return ($a['order'] <=> $b['order']) ?: ($a['id'] <=> $b['id']);
    }

    /**
     * Parent id that exists in the list; a dangling parent reference counts as top level.
     *
     * @return array<int, ?int>
     */
    private static function effectiveParents(array $members): array
    {
        $ids = array_flip(array_column($members, 'id'));
        $parents = [];
        foreach ($members as $m) {
            $parents[$m['id']] = $m['parentId'] !== null && isset($ids[$m['parentId']]) ? $m['parentId'] : null;
        }

        return $parents;
    }

    /** Members reporting to `$parentId` (`null` = top level), in display order. */
    private static function siblingsOf(array $members, ?int $parentId): array
    {
        $parents = self::effectiveParents($members);
        $siblings = array_values(array_filter($members, fn (array $m): bool => $parents[$m['id']] === $parentId));
        usort($siblings, self::byOrder(...));

        return $siblings;
    }

    /** Top-down forest. Members caught in a cycle (never reachable from a root) are surfaced as roots. */
    public static function buildOrgTree(array $members): array
    {
        $parents = self::effectiveParents($members);
        $sorted = array_values($members);
        usort($sorted, self::byOrder(...));
        $groups = [];
        foreach ($sorted as $member) {
            $groups[$parents[$member['id']] ?? 'root'][] = $member;
        }
        $seen = [];
        $build = function (array $member) use (&$build, &$seen, $groups): array {
            $seen[$member['id']] = true;
            $children = array_filter($groups[$member['id']] ?? [], fn (array $child): bool => ! isset($seen[$child['id']]));
            $nodes = [];
            foreach ($children as $child) {
                // The filter above ran before the siblings were built, as in the TS source.
                $nodes[] = $build($child);
            }

            return ['member' => $member, 'children' => $nodes];
        };
        $roots = array_map($build, $groups['root'] ?? []);
        foreach ($sorted as $member) {
            if (! isset($seen[$member['id']])) {
                $roots[] = $build($member);
            }
        }

        return $roots;
    }

    /** Ids of `$id` and every member below it. @return array<int, true> keyed by id */
    private static function subtreeIds(array $members, int $id): array
    {
        $children = [];
        foreach ($members as $m) {
            if ($m['parentId'] !== null) {
                $children[$m['parentId']][] = $m['id'];
            }
        }
        $result = [$id => true];
        $stack = [$id];
        while ($stack !== []) {
            foreach ($children[array_pop($stack)] ?? [] as $child) {
                if (! isset($result[$child])) {
                    $result[$child] = true;
                    $stack[] = $child;
                }
            }
        }

        return $result;
    }

    /** True when making `$parentId` the manager of `$id` would create a loop. */
    public static function wouldCycle(array $members, int $id, ?int $parentId): bool
    {
        return $parentId !== null && isset(self::subtreeIds($members, $id)[$parentId]);
    }

    /** True when following parents from any member ever returns to a visited member. */
    private static function hasCycle(array $members): bool
    {
        $parents = [];
        foreach ($members as $m) {
            $parents[$m['id']] = $m['parentId'];
        }
        $done = [];
        foreach ($members as $m) {
            $path = [];
            for ($cursor = $m['id']; $cursor !== null && ! isset($done[$cursor]); $cursor = $parents[$cursor] ?? null) {
                if (isset($path[$cursor])) {
                    return true;
                }
                $path[$cursor] = true;
            }
            $done += $path;
        }

        return false;
    }

    /** @param list<array{id: int, parentId: ?int, order: int}> $placements */
    private static function applyPlacements(array $members, array $placements): array
    {
        $next = [];
        foreach ($placements as $p) {
            $next[$p['id']] = $p;
        }

        return array_map(function (array $m) use ($next): array {
            $p = $next[$m['id']] ?? null;

            return $p ? [...$m, 'parentId' => $p['parentId'], 'order' => $p['order']] : $m;
        }, array_values($members));
    }

    /** Renumbers `$ids` 1..n under `$parentId`, returning only rows whose parent or order changes. */
    private static function renumber(array $byId, array $ids, ?int $parentId): array
    {
        $changed = [];
        foreach (array_values($ids) as $i => $id) {
            $m = $byId[$id];
            if ($m['parentId'] !== $parentId || $m['order'] !== $i + 1) {
                $changed[] = ['id' => $id, 'parentId' => $parentId, 'order' => $i + 1];
            }
        }

        return $changed;
    }

    /**
     * Deleting `$id` hands its direct reports to its own manager (or makes them roots), keeping their order and
     * slotting them where `$id` stood. Returns the changed placements of the survivors.
     */
    public static function reparentOnDelete(array $members, int $id): array
    {
        $byId = [];
        foreach ($members as $m) {
            $byId[$m['id']] = $m;
        }
        if (! isset($byId[$id])) {
            return [];
        }
        $parent = self::effectiveParents($members)[$id] ?? null;
        $reports = array_map(fn (array $m): int => $m['id'], self::siblingsOf($members, $id));
        $list = array_map(fn (array $m): int => $m['id'], self::siblingsOf($members, $parent));
        array_splice($list, array_search($id, $list, true), 1, $reports);

        return self::renumber($byId, $list, $parent);
    }

    /** Server-side check of a reorder payload against the department's current members. */
    public static function placementsError(array $existing, array $placements): ?string
    {
        $ids = array_flip(array_column($existing, 'id'));
        $seen = [];
        foreach ($placements as $p) {
            if (! isset($ids[$p['id']])) {
                return 'A member does not belong to this department.';
            }
            if (isset($seen[$p['id']])) {
                return 'A member is listed more than once.';
            }
            $seen[$p['id']] = true;
            if ($p['parentId'] !== null && ! isset($ids[$p['parentId']])) {
                return 'The selected parent does not belong to this department.';
            }
            if ($p['parentId'] === $p['id']) {
                return 'A member cannot report to itself.';
            }
        }
        if (self::hasCycle(self::applyPlacements($existing, $placements))) {
            return 'A member cannot report to itself or to one of its own subordinates.';
        }

        return null;
    }
}
