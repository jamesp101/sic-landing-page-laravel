<?php

namespace Tests\Unit\Admin\Trees;

use App\Admin\OrgTree as T;
use PHPUnit\Framework\TestCase;

/** Port of org-tree.test.ts (every pure helper; the tree-edit-session ops stay client-side). */
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

    private static function ids(array $set): array
    {
        $ids = array_keys($set);
        sort($ids);

        return $ids;
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

    public function test_subtree_ids_include_the_root_and_all_descendants(): void
    {
        $this->assertSame([2, 4], self::ids(T::subtreeIds(self::sample(), 2)));
        $this->assertSame([1, 2, 3, 4, 5], self::ids(T::subtreeIds(self::sample(), 1)));
    }

    public function test_would_cycle_rejects_self_and_descendants_but_allows_others(): void
    {
        $this->assertTrue(T::wouldCycle(self::sample(), 1, 1));
        $this->assertTrue(T::wouldCycle(self::sample(), 1, 4));
        $this->assertFalse(T::wouldCycle(self::sample(), 2, 3));
        $this->assertFalse(T::wouldCycle(self::sample(), 4, null));
    }

    public function test_has_cycle_finds_loops_in_a_full_parent_map(): void
    {
        $this->assertFalse(T::hasCycle(self::sample()));
        $this->assertTrue(T::hasCycle([self::m(1, 2, 1), self::m(2, 3, 1), self::m(3, 1, 1)]));
        $this->assertTrue(T::hasCycle([self::m(1, 1, 1)]));
    }

    public function test_can_drop_forbids_dropping_onto_itself_or_its_own_subtree(): void
    {
        $this->assertFalse(T::canDrop(self::sample(), 2, 2));
        $this->assertFalse(T::canDrop(self::sample(), 1, 5));
        $this->assertTrue(T::canDrop(self::sample(), 4, 3));
    }

    public function test_move_reparents_under_another_member_and_renumbers_both_sibling_lists(): void
    {
        $moved = T::moveRelative(self::sample(), 4, 3, 'child');
        $this->assertContains(['id' => 4, 'parentId' => 3, 'order' => 2], $moved);
        $this->assertSame([[1, [2, [3, [5, 4]]]], 6], self::shapeOf(T::applyPlacements(self::sample(), $moved)));
    }

    public function test_move_reorders_among_siblings_and_reports_only_changed_rows(): void
    {
        $moved = T::moveRelative(self::sample(), 3, 2, 'before');
        $this->assertSame([['id' => 3, 'parentId' => 1, 'order' => 1], ['id' => 2, 'parentId' => 1, 'order' => 2]], $moved);
        $this->assertSame([[1, [[3, [5]], [2, [4]]]], 6], self::shapeOf(T::applyPlacements(self::sample(), $moved)));
    }

    public function test_move_places_after_a_target_and_between_roots(): void
    {
        $this->assertSame([[1, [[3, [5]], [2, [4]]]], 6], self::shapeOf(T::applyPlacements(self::sample(), T::moveRelative(self::sample(), 2, 3, 'after'))));
        $this->assertSame([4, [1, [2, [3, [5]]]], 6], self::shapeOf(T::applyPlacements(self::sample(), T::moveRelative(self::sample(), 4, 1, 'before'))));
    }

    public function test_move_to_root_makes_the_member_the_last_root(): void
    {
        $this->assertSame([[1, [[2, [4]]]], 6, [3, [5]]], self::shapeOf(T::applyPlacements(self::sample(), T::moveToRoot(self::sample(), 3))));
    }

    public function test_a_no_op_is_empty_and_an_illegal_move_is_null(): void
    {
        $this->assertSame([], T::moveRelative(self::sample(), 2, 3, 'before'));
        $this->assertNull(T::moveRelative(self::sample(), 1, 4, 'child'));
        $this->assertNull(T::moveRelative(self::sample(), 1, 1, 'after'));
        $this->assertNull(T::moveMember(self::sample(), 1, 99, 0));
        $this->assertNull(T::moveMember(self::sample(), 99, null, 0));
    }

    public function test_move_clamps_the_index(): void
    {
        $swapped = [[1, [[3, [5]], [2, [4]]]], 6];
        $this->assertSame($swapped, self::shapeOf(T::applyPlacements(self::sample(), T::moveMember(self::sample(), 2, 1, 50))));
        $this->assertSame($swapped, self::shapeOf(T::applyPlacements(self::sample(), T::moveMember(self::sample(), 3, 1, -4))));
    }

    public function test_invert_placements_restores_the_previous_state(): void
    {
        $forward = T::moveRelative(self::sample(), 4, 3, 'child');
        $back = T::invertPlacements(self::sample(), $forward);
        $this->assertSame(self::sample(), T::applyPlacements(T::applyPlacements(self::sample(), $forward), $back));
    }

    private function key(int $id, string $move): ?array
    {
        $placements = T::keyboardMove(self::sample(), $id, $move);

        return $placements === null ? null : self::shapeOf(T::applyPlacements(self::sample(), $placements));
    }

    public function test_keyboard_up_and_down_swap_with_a_sibling(): void
    {
        $swapped = [[1, [[3, [5]], [2, [4]]]], 6];
        $this->assertSame($swapped, $this->key(3, 'up'));
        $this->assertSame($swapped, $this->key(2, 'down'));
        $this->assertNull($this->key(2, 'up'));
        $this->assertNull($this->key(3, 'down'));
        $this->assertSame([6, [1, [[2, [4]], [3, [5]]]]], $this->key(1, 'down'));
    }

    public function test_keyboard_out_reports_to_the_grandparent_right_after_the_former_manager(): void
    {
        $this->assertSame([[1, [2, 4, [3, [5]]]], 6], $this->key(4, 'out'));
        $this->assertSame([[1, [[3, [5]]]], [2, [4]], 6], $this->key(2, 'out'));
        $this->assertNull($this->key(1, 'out'));
    }

    public function test_keyboard_in_reports_to_the_previous_sibling(): void
    {
        $this->assertSame([[1, [[2, [4, [3, [5]]]]]], 6], $this->key(3, 'in'));
        $this->assertSame([[1, [[2, [4]], [3, [5]], 6]]], $this->key(6, 'in'));
        $this->assertNull($this->key(2, 'in'));
        $this->assertNull(T::keyboardMove(self::sample(), 99, 'up'));
    }

    public function test_reparent_on_delete_moves_reports_up_in_the_deleted_members_slot_keeping_order(): void
    {
        $members = [...self::sample(), self::m(7, 1, 3)];
        $this->assertSame([['id' => 4, 'parentId' => 1, 'order' => 1]], T::reparentOnDelete($members, 2));
        $this->assertSame([[1, [4, [3, [5]], 7]], 6], self::shapeOf(T::removeMember($members, 2)));
    }

    public function test_reparent_on_delete_makes_reports_of_a_root_top_level_between_the_neighbouring_roots(): void
    {
        $this->assertSame([
            ['id' => 2, 'parentId' => null, 'order' => 1],
            ['id' => 3, 'parentId' => null, 'order' => 2],
            ['id' => 6, 'parentId' => null, 'order' => 3],
        ], T::reparentOnDelete(self::sample(), 1));
        $this->assertSame([[2, [4]], [3, [5]], 6], self::shapeOf(T::removeMember(self::sample(), 1)));
    }

    public function test_reparent_on_delete_is_empty_for_a_leaf_that_needs_no_renumbering_and_for_unknown_ids(): void
    {
        $this->assertSame([], T::reparentOnDelete(self::sample(), 6));
        $this->assertSame([], T::reparentOnDelete(self::sample(), 99));
        $this->assertSame([[1, [[2, [4]], [3, [5]]]]], self::shapeOf(T::removeMember(self::sample(), 6)));
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

    public function test_siblings_next_order_and_position(): void
    {
        $this->assertSame([1, 6], array_column(T::siblingsOf(self::sample(), null), 'id'));
        $this->assertSame(3, T::nextOrder(self::sample(), 1));
        $this->assertSame(1, T::nextOrder(self::sample(), 4));
        $this->assertSame(['index' => 1, 'count' => 1, 'level' => 3, 'parentId' => 3], T::positionOf(self::sample(), 5));
        $this->assertSame(['index' => 2, 'count' => 2, 'level' => 1, 'parentId' => null], T::positionOf(self::sample(), 6));
    }

    public function test_parent_options_are_depth_first_and_exclude_the_member_and_its_subtree(): void
    {
        $this->assertSame([[1, 0], [2, 1], [4, 2], [3, 1], [5, 2], [6, 0]], array_map(fn ($o) => [$o['id'], $o['depth']], T::parentOptions(self::sample())));
        $this->assertSame([[1, 0], [3, 1], [5, 2], [6, 0]], array_map(fn ($o) => [$o['id'], $o['depth']], T::parentOptions(self::sample(), 2)));
    }
}
