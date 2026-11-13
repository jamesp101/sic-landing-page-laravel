import { describe, expect, it } from 'vitest';
import {
    applyPlacements,
    buildOrgTree,
    canDrop,
    hasCycle,
    invertPlacements,
    keyboardMove,
    matchMembers,
    moveMember,
    moveRelative,
    moveToRoot,
    nextOrder,
    parentOptions,
    positionOf,
    siblingsOf,
    subtreeIds,
    orgTreeOps,
    type OrgMember,
    type OrgNode,
} from './org-tree';

const m = (id: number, parentId: number | null, order: number, name = `M${id}`): OrgMember => ({
    id,
    name,
    role: `Role ${id}`,
    parentId,
    order,
    imageUrl: null,
});

/*
 * 1 Dean
 * ├─ 2 CS head
 * │  └─ 4 Instructor
 * └─ 3 IS head
 *    └─ 5 Instructor
 * 6 Registrar (second root)
 */
const sample = (): OrgMember[] => [m(1, null, 1), m(2, 1, 1), m(3, 1, 2), m(4, 2, 1), m(5, 3, 1), m(6, null, 2)];

const shape = (nodes: OrgNode[]): unknown[] =>
    nodes.map((n) => (n.children.length ? [n.member.id, shape(n.children)] : n.member.id));
const shapeOf = (members: OrgMember[]) => shape(buildOrgTree(members));

describe('cycle detection', () => {
    it('subtreeIds includes the root and all descendants', () => {
        expect([...subtreeIds(sample(), 2)].sort((a, b) => a - b)).toEqual([2, 4]);
        expect([...subtreeIds(sample(), 1)].sort((a, b) => a - b)).toEqual([1, 2, 3, 4, 5]);
    });

    it('hasCycle finds loops in a full parent map', () => {
        expect(hasCycle(sample())).toBe(false);
        expect(hasCycle([m(1, 2, 1), m(2, 3, 1), m(3, 1, 1)])).toBe(true);
        expect(hasCycle([m(1, 1, 1)])).toBe(true);
    });

    it('canDrop forbids dropping onto itself or its own subtree', () => {
        expect(canDrop(sample(), 2, 2)).toBe(false);
        expect(canDrop(sample(), 1, 5)).toBe(false);
        expect(canDrop(sample(), 4, 3)).toBe(true);
    });
});

describe('moveMember / moveRelative', () => {
    it('reparents under another member and renumbers both sibling lists', () => {
        const moved = moveRelative(sample(), 4, 3, 'child')!;
        expect(moved).toEqual(expect.arrayContaining([{ id: 4, parentId: 3, order: 2 }]));
        expect(shapeOf(applyPlacements(sample(), moved))).toEqual([[1, [2, [3, [5, 4]]]], 6]);
    });

    it('reorders among siblings and reports only changed rows', () => {
        const moved = moveRelative(sample(), 3, 2, 'before')!;
        expect(moved).toEqual([
            { id: 3, parentId: 1, order: 1 },
            { id: 2, parentId: 1, order: 2 },
        ]);
        expect(shapeOf(applyPlacements(sample(), moved))).toEqual([
            [
                1,
                [
                    [3, [5]],
                    [2, [4]],
                ],
            ],
            6,
        ]);
    });

    it('places after a target and between roots', () => {
        expect(shapeOf(applyPlacements(sample(), moveRelative(sample(), 2, 3, 'after')!))).toEqual([
            [
                1,
                [
                    [3, [5]],
                    [2, [4]],
                ],
            ],
            6,
        ]);
        expect(shapeOf(applyPlacements(sample(), moveRelative(sample(), 4, 1, 'before')!))).toEqual([
            4,
            [1, [2, [3, [5]]]],
            6,
        ]);
    });

    it('moveToRoot makes the member the last root', () => {
        const moved = moveToRoot(sample(), 3)!;
        expect(shapeOf(applyPlacements(sample(), moved))).toEqual([[1, [[2, [4]]]], 6, [3, [5]]]);
    });

    it('returns [] for a no-op and null for an illegal move', () => {
        expect(moveRelative(sample(), 2, 3, 'before')).toEqual([]);
        expect(moveRelative(sample(), 1, 4, 'child')).toBeNull();
        expect(moveRelative(sample(), 1, 1, 'after')).toBeNull();
        expect(moveMember(sample(), 1, 99, 0)).toBeNull();
        expect(moveMember(sample(), 99, null, 0)).toBeNull();
    });

    it('clamps the index', () => {
        expect(shapeOf(applyPlacements(sample(), moveMember(sample(), 2, 1, 50)!))).toEqual([
            [
                1,
                [
                    [3, [5]],
                    [2, [4]],
                ],
            ],
            6,
        ]);
        expect(shapeOf(applyPlacements(sample(), moveMember(sample(), 3, 1, -4)!))).toEqual([
            [
                1,
                [
                    [3, [5]],
                    [2, [4]],
                ],
            ],
            6,
        ]);
    });

    it('invertPlacements restores the previous state', () => {
        const forward = moveRelative(sample(), 4, 3, 'child')!;
        const back = invertPlacements(sample(), forward);
        expect(applyPlacements(applyPlacements(sample(), forward), back)).toEqual(sample());
    });
});

describe('keyboardMove', () => {
    const apply = (id: number, move: Parameters<typeof keyboardMove>[2]) => {
        const placements = keyboardMove(sample(), id, move);
        return placements && shapeOf(applyPlacements(sample(), placements));
    };

    it('up and down swap with a sibling', () => {
        expect(apply(3, 'up')).toEqual([
            [
                1,
                [
                    [3, [5]],
                    [2, [4]],
                ],
            ],
            6,
        ]);
        expect(apply(2, 'down')).toEqual([
            [
                1,
                [
                    [3, [5]],
                    [2, [4]],
                ],
            ],
            6,
        ]);
        expect(apply(2, 'up')).toBeNull();
        expect(apply(3, 'down')).toBeNull();
        expect(apply(1, 'down')).toEqual([
            6,
            [
                1,
                [
                    [2, [4]],
                    [3, [5]],
                ],
            ],
        ]);
    });

    it('out reports to the grandparent right after the former manager', () => {
        expect(apply(4, 'out')).toEqual([[1, [2, 4, [3, [5]]]], 6]);
        expect(apply(2, 'out')).toEqual([[1, [[3, [5]]]], [2, [4]], 6]);
        expect(apply(1, 'out')).toBeNull();
    });

    it('in reports to the previous sibling', () => {
        expect(apply(3, 'in')).toEqual([[1, [[2, [4, [3, [5]]]]]], 6]);
        expect(apply(6, 'in')).toEqual([[1, [[2, [4]], [3, [5]], 6]]]);
        expect(apply(2, 'in')).toBeNull();
        expect(keyboardMove(sample(), 99, 'up')).toBeNull();
    });
});

describe('misc helpers', () => {
    it('siblingsOf, nextOrder and positionOf', () => {
        expect(siblingsOf(sample(), null).map((x) => x.id)).toEqual([1, 6]);
        expect(nextOrder(sample(), 1)).toBe(3);
        expect(nextOrder(sample(), 4)).toBe(1);
        expect(positionOf(sample(), 5)).toEqual({ index: 1, count: 1, level: 3, parentId: 3 });
        expect(positionOf(sample(), 6)).toEqual({ index: 2, count: 2, level: 1, parentId: null });
    });

    it('parentOptions is depth-first and excludes the member and its subtree', () => {
        expect(parentOptions(sample()).map((o) => [o.id, o.depth])).toEqual([
            [1, 0],
            [2, 1],
            [4, 2],
            [3, 1],
            [5, 2],
            [6, 0],
        ]);
        expect(parentOptions(sample(), 2).map((o) => o.id)).toEqual([1, 3, 5, 6]);
    });

    it('matchMembers searches name and role case-insensitively', () => {
        const members = [m(1, null, 1, 'Ada Lovelace'), m(2, null, 2, 'Grace Hopper')];
        expect(matchMembers(members, 'LOVE')).toEqual([1]);
        expect(matchMembers(members, 'role 2')).toEqual([2]);
        expect(matchMembers(members, '  ')).toEqual([]);
    });
});

describe('orgTreeOps', () => {
    it('applies and inverts moves and renames', () => {
        const members = sample();
        const moves = moveRelative(members, 6, 2, 'before')!;
        const moved = orgTreeOps.apply(members, { kind: 'place', placements: moves });
        expect(orgTreeOps.apply(moved, orgTreeOps.invert(members, { kind: 'place', placements: moves }))).toEqual(
            members,
        );

        const rename = { kind: 'rename', id: 2, name: 'New' } as const;
        const renamed = orgTreeOps.apply(members, rename);
        expect(renamed.find((x) => x.id === 2)?.name).toBe('New');
        expect(orgTreeOps.apply(renamed, orgTreeOps.invert(members, rename))).toEqual(members);
    });

    it('treats an empty move or an unchanged name as nothing to do', () => {
        const members = sample();
        expect(orgTreeOps.isNoop?.(members, { kind: 'place', placements: [] })).toBe(true);
        expect(orgTreeOps.isNoop?.(members, { kind: 'rename', id: 2, name: 'M2' })).toBe(true);
        expect(orgTreeOps.isNoop?.(members, { kind: 'rename', id: 2, name: 'Other' })).toBe(false);
    });
});
