<?php

namespace Tests\Unit\Admin\Trees;

use App\Admin\OrgTree as T;
use PHPUnit\Framework\TestCase;

/** The server-side org chart rules; the editor's tree moves live in org-tree.ts. */
class OrgTreeTest extends TestCase
{
    private static function m(int $id, ?int $parentId, int $order, ?string $name = null): array
    {
        return ['id' => $id, 'name' => $name ?? "M{$id}", 'role' => "Role {$id}", 'parentId' => $parentId, 'order' => $order, 'imageUrl' => null];
    }

    /*
     * 1 Dean
     * ├─ 2 CS head
     * │  └─ 4 Instructor
     * └─ 3 IS head
     *    └─ 5 Instructor
     * 6 Registrar (second root)
     */
    private static function sample(): array
    {
        return [self::m(1, null, 1), self::m(2, 1, 1), self::m(3, 1, 2), self::m(4, 2, 1), self::m(5, 3, 1), self::m(6, null, 2)];
    }

    private static function shape(array $nodes): array
    {
        return array_map(fn (array $n) => $n['children'] ? [$n['member']['id'], self::shape($n['children'])] : $n['member']['id'], $nodes);
    }

    private static function shapeOf(array $members): array
    {
        return self::shape(T::buildOrgTree($members));
    }

    public function test_build_nests_by_parent_and_sorts_siblings_by_order_then_id(): void
    {
        $this->assertSame([[1, [[2, [4]], [3, [5]]]], 6], self::shapeOf(self::sample()));
        $this->assertSame([1, 2, 3], self::shapeOf([self::m(3, null, 2), self::m(2, null, 1), self::m(1, null, 1)]));
    }

    public function test_build_treats_dangling_parents_as_roots_and_surfaces_cycles(): void
    {
        $this->assertSame([[1, [2]]], self::shapeOf([self::m(1, 99, 1), self::m(2, 1, 1)]));
        $looped = T::buildOrgTree([self::m(1, 2, 1), self::m(2, 1, 1), self::m(3, null, 1)]);
        $roots = array_map(fn ($n) => $n['member']['id'], $looped);
        sort($roots);
        $this->assertSame([1, 3], $roots);
        $all = function (array $nodes) use (&$all): array {
            return array_merge(...array_map(fn ($n) => [$n['member']['id'], ...$all($n['children'])], $nodes) ?: [[]]);
        };
        $ids = $all($looped);
        sort($ids);
        $this->assertSame([1, 2, 3], $ids);
    }

    public function test_build_returns_an_empty_forest_for_no_members(): void
    {
        $this->assertSame([], T::buildOrgTree([]));
    }

    public function test_would_cycle_rejects_self_and_descendants_but_allows_others(): void
    {
        $this->assertTrue(T::wouldCycle(self::sample(), 1, 1));
        $this->assertTrue(T::wouldCycle(self::sample(), 1, 4));
        $this->assertFalse(T::wouldCycle(self::sample(), 2, 3));
        $this->assertFalse(T::wouldCycle(self::sample(), 4, null));
    }

    public function test_reparent_on_delete_moves_reports_up_in_the_deleted_members_slot_keeping_order(): void
    {
        $members = [...self::sample(), self::m(7, 1, 3)];
        $this->assertSame([['id' => 4, 'parentId' => 1, 'order' => 1]], T::reparentOnDelete($members, 2));
    }

    public function test_reparent_on_delete_makes_reports_of_a_root_top_level_between_the_neighbouring_roots(): void
    {
        $this->assertSame([
            ['id' => 2, 'parentId' => null, 'order' => 1],
            ['id' => 3, 'parentId' => null, 'order' => 2],
            ['id' => 6, 'parentId' => null, 'order' => 3],
        ], T::reparentOnDelete(self::sample(), 1));
    }

    public function test_reparent_on_delete_is_empty_for_a_leaf_that_needs_no_renumbering_and_for_unknown_ids(): void
    {
        $this->assertSame([], T::reparentOnDelete(self::sample(), 6));
        $this->assertSame([], T::reparentOnDelete(self::sample(), 99));
    }

    public function test_placements_error_accepts_a_valid_payload(): void
    {
        $this->assertNull(T::placementsError(self::sample(), [['id' => 4, 'parentId' => 3, 'order' => 2]]));
        $this->assertNull(T::placementsError(self::sample(), [['id' => 2, 'parentId' => null, 'order' => 1]]));
    }

    public function test_placements_error_rejects_foreign_ids_foreign_parents_duplicates_self_parents_and_cycles(): void
    {
        $this->assertMatchesRegularExpression('/department/', T::placementsError(self::sample(), [['id' => 99, 'parentId' => null, 'order' => 1]]));
        $this->assertMatchesRegularExpression('/parent/', T::placementsError(self::sample(), [['id' => 2, 'parentId' => 99, 'order' => 1]]));
        $this->assertMatchesRegularExpression('/more than once/', T::placementsError(self::sample(), [['id' => 2, 'parentId' => 1, 'order' => 1], ['id' => 2, 'parentId' => 1, 'order' => 2]]));
        $this->assertMatchesRegularExpression('/itself/', T::placementsError(self::sample(), [['id' => 2, 'parentId' => 2, 'order' => 1]]));
        $this->assertMatchesRegularExpression('/subordinates/', T::placementsError(self::sample(), [['id' => 1, 'parentId' => 4, 'order' => 1]]));
        $this->assertMatchesRegularExpression('/subordinates/', T::placementsError(self::sample(), [['id' => 2, 'parentId' => 4, 'order' => 1]]));
    }
}
