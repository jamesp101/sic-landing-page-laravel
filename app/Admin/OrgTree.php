<?php

namespace App\Admin;

/**
 * Pure helpers for the department org chart (shared by the admin editor's server side and the `members` actions).
 * Siblings are ordered by (`order`, `id`) exactly like the public chart; every move renumbers the affected sibling
 * lists 1..n and reports only the rows that actually changed.
 *
 * A member is an array with at least `id`, `parentId` (?int) and `order`; the org-chart view model adds
 * `name`, `role` and `imageUrl`. A placement is `['id' => int, 'parentId' => ?int, 'order' => int]`.
 * A tree node is `['member' => array, 'children' => list<node>]`.
 */
final class OrgTree
{
    /** Index that means "last" for `moveMember` (JavaScript's Number.MAX_SAFE_INTEGER). */
    public const LAST = PHP_INT_MAX;

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
    public static function siblingsOf(array $members, ?int $parentId): array
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
    public static function subtreeIds(array $members, int $id): array
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
    public static function hasCycle(array $members): bool
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
    public static function applyPlacements(array $members, array $placements): array
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

    /** The placements that restore the current values of the members touched by `$placements`. */
    public static function invertPlacements(array $members, array $placements): array
    {
        $current = [];
        foreach ($members as $m) {
            $current[$m['id']] = $m;
        }
        $inverse = [];
        foreach ($placements as $p) {
            if (isset($current[$p['id']])) {
                $m = $current[$p['id']];
                $inverse[] = ['id' => $m['id'], 'parentId' => $m['parentId'], 'order' => $m['order']];
            }
        }

        return $inverse;
    }

    /** Order for a new last child of `$parentId`. */
    public static function nextOrder(array $members, ?int $parentId): int
    {
        return array_reduce(self::siblingsOf($members, $parentId), fn (int $max, array $m): int => max($max, $m['order']), 0) + 1;
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
     * Moves `$id` under `$parentId` at `$index` among that parent's other children (clamped).
     * Returns the changed placements (empty = nothing to do) or `null` when the move is illegal.
     */
    public static function moveMember(array $members, int $id, ?int $parentId, int $index): ?array
    {
        $byId = [];
        foreach ($members as $m) {
            $byId[$m['id']] = $m;
        }
        if (! isset($byId[$id])) {
            return null;
        }
        if ($parentId !== null && (! isset($byId[$parentId]) || self::wouldCycle($members, $id, $parentId))) {
            return null;
        }

        $oldParent = self::effectiveParents($members)[$id] ?? null;
        $target = array_values(array_map(fn (array $m): int => $m['id'], array_filter(self::siblingsOf($members, $parentId), fn (array $m): bool => $m['id'] !== $id)));
        array_splice($target, max(0, min($index, count($target))), 0, [$id]);
        $changes = self::renumber($byId, $target, $parentId);
        if ($oldParent !== $parentId) {
            $old = array_values(array_map(fn (array $m): int => $m['id'], array_filter(self::siblingsOf($members, $oldParent), fn (array $m): bool => $m['id'] !== $id)));
            array_push($changes, ...self::renumber($byId, $old, $oldParent));
        }

        return $changes;
    }

    /** Whether `$id` may be dropped next to / onto `$targetId` (never onto itself or its own subtree). */
    public static function canDrop(array $members, int $id, int $targetId): bool
    {
        return in_array($id, array_column($members, 'id'), true) && ! isset(self::subtreeIds($members, $id)[$targetId]);
    }

    /** Drag-and-drop move: 'before'/'after' `$targetId` as a sibling, or as its last 'child'. */
    public static function moveRelative(array $members, int $id, int $targetId, string $position): ?array
    {
        if (! self::canDrop($members, $id, $targetId)) {
            return null;
        }
        if ($position === 'child') {
            return self::moveMember($members, $id, $targetId, self::LAST);
        }
        $parent = self::effectiveParents($members)[$targetId] ?? null;
        $others = array_values(array_filter(self::siblingsOf($members, $parent), fn (array $m): bool => $m['id'] !== $id));
        $at = 0;
        foreach ($others as $i => $m) {
            if ($m['id'] === $targetId) {
                $at = $i;
                break;
            }
        }

        return self::moveMember($members, $id, $parent, $position === 'before' ? $at : $at + 1);
    }

    /** Drop on the top-level lane: becomes the last root. */
    public static function moveToRoot(array $members, int $id): ?array
    {
        return self::moveMember($members, $id, null, self::LAST);
    }

    /**
     * Keyboard restructuring: 'up'/'down' swap with a sibling, 'out' reports to the grandparent (or becomes a root)
     * right after its former manager, 'in' reports to the previous sibling as its last child. `null` = not possible.
     */
    public static function keyboardMove(array $members, int $id, string $move): ?array
    {
        $parents = self::effectiveParents($members);
        if (! array_key_exists($id, $parents)) {
            return null;
        }
        $parent = $parents[$id];
        $siblings = self::siblingsOf($members, $parent);
        $index = 0;
        foreach ($siblings as $i => $m) {
            if ($m['id'] === $id) {
                $index = $i;
                break;
            }
        }

        switch ($move) {
            case 'up':
                return $index > 0 ? self::moveMember($members, $id, $parent, $index - 1) : null;
            case 'down':
                return $index < count($siblings) - 1 ? self::moveMember($members, $id, $parent, $index + 1) : null;
            case 'out':
                if ($parent === null) {
                    return null;
                }
                $grand = $parents[$parent] ?? null;
                $at = 0;
                foreach (self::siblingsOf($members, $grand) as $i => $m) {
                    if ($m['id'] === $parent) {
                        $at = $i;
                        break;
                    }
                }

                return self::moveMember($members, $id, $grand, $at + 1);
            case 'in':
                return $index > 0 ? self::moveMember($members, $id, $siblings[$index - 1]['id'], self::LAST) : null;
        }

        return null;
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

    /** The members after `$id` is deleted and its reports moved up. */
    public static function removeMember(array $members, int $id): array
    {
        return self::applyPlacements(
            array_values(array_filter($members, fn (array $m): bool => $m['id'] !== $id)),
            self::reparentOnDelete($members, $id),
        );
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

    /**
     * Candidate managers in chart order (depth-first), leaving out `$excludeId` and everything below it.
     *
     * @return list<array{id: int, name: string, role: string, depth: int}>
     */
    public static function parentOptions(array $members, ?int $excludeId = null): array
    {
        $out = [];
        $walk = function (array $nodes, int $depth) use (&$walk, &$out, $excludeId): void {
            foreach ($nodes as $node) {
                if ($node['member']['id'] === $excludeId) {
                    continue;
                }
                $out[] = ['id' => $node['member']['id'], 'name' => $node['member']['name'], 'role' => $node['member']['role'], 'depth' => $depth];
                $walk($node['children'], $depth + 1);
            }
        };
        $walk(self::buildOrgTree($members), 0);

        return $out;
    }

    /**
     * 1-based position among siblings, sibling count, depth (1 = top level) and manager id of `$id`.
     *
     * @return array{index: int, count: int, level: int, parentId: ?int}
     */
    public static function positionOf(array $members, int $id): array
    {
        $parents = self::effectiveParents($members);
        $parent = $parents[$id] ?? null;
        $siblings = self::siblingsOf($members, $parent);
        $level = 1;
        for ($cursor = $parent; $cursor !== null; $cursor = $parents[$cursor] ?? null) {
            $level++;
        }
        $index = -1;
        foreach ($siblings as $i => $m) {
            if ($m['id'] === $id) {
                $index = $i;
                break;
            }
        }

        return ['index' => $index + 1, 'count' => count($siblings), 'level' => $level, 'parentId' => $parent];
    }
}
