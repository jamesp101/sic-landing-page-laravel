<?php

namespace App\Admin;

use App\Models\Member;
use App\OrgChart\OrgTree;
use App\Support\RequestError;

/** Rules for org-chart members that need the database. */
final class MemberRules
{
    /** Parent must be in the same department and must not be the member itself or one of its descendants. */
    public static function assertValidParent(int $departmentId, ?int $parentId, ?int $memberId = null): void
    {
        if ($parentId === null) {
            return;
        }
        $tree = self::tree($departmentId);
        if (! in_array($parentId, array_column($tree, 'id'), true)) {
            throw RequestError::badRequest('The selected parent does not belong to this department.');
        }
        if ($memberId !== null && OrgTree::wouldCycle($tree, $memberId, $parentId)) {
            throw RequestError::badRequest('A member cannot report to itself or to one of its own subordinates.');
        }
    }

    /**
     * The department's members as `['id', 'parentId', 'order']` rows (what `OrgTree` works on).
     *
     * @return list<array{id: int, parentId: ?int, order: int}>
     */
    public static function tree(int $departmentId): array
    {
        return Member::query()->where('department_id', $departmentId)->get(['id', 'parent_id', 'order'])
            ->map(fn (Member $m): array => ['id' => (int) $m->id, 'parentId' => $m->parent_id === null ? null : (int) $m->parent_id, 'order' => (int) $m->order])
            ->all();
    }
}
