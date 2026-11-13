import { describe, expect, test } from 'vitest';
import {
    buildOrgTree,
    placementsError,
    reparentOnDelete,
    removeMember,
    wouldCycle,
    type OrgMember,
    type OrgNode,
    type OrgPlacement,
} from '../org-tree';
import { fixture } from './fixtures';

type Triple = [id: number, parentId: number | null, order: number];
type Shape = number | [number, Shape[]];

interface OrgTreeFixture {
    dean: Triple[];
    buildOrgTree: { name: string; members: Triple[] | 'dean'; shape: Shape[] }[];
    wouldCycle: { name: string; members: Triple[] | 'dean'; id: number; parentId: number | null; result: boolean }[];
    reparentOnDelete: {
        name: string;
        members: Triple[] | 'dean';
        id: number;
        placements: OrgPlacement[];
        shape: Shape[];
    }[];
    placementsError: { name: string; members: Triple[] | 'dean'; placements: OrgPlacement[]; error: string | null }[];
}

const data = fixture<OrgTreeFixture>('OrgChart', 'org-tree');

const membersOf = (spec: Triple[] | 'dean'): OrgMember[] =>
    (spec === 'dean' ? data.dean : spec).map(([id, parentId, order]) => ({
        id,
        name: `M${id}`,
        role: `Role ${id}`,
        parentId,
        order,
        imageUrl: null,
    }));

const shape = (nodes: OrgNode[]): Shape[] =>
    nodes.map((n) => (n.children.length ? [n.member.id, shape(n.children)] : n.member.id));

describe('org-tree matches the server (App\\OrgChart\\OrgTree)', () => {
    test.each(data.buildOrgTree.map((c) => [c.name, c] as const))('buildOrgTree: %s', (_name, c) => {
        expect(shape(buildOrgTree(membersOf(c.members)))).toEqual(c.shape);
    });

    test.each(data.wouldCycle.map((c) => [c.name, c] as const))('wouldCycle: %s', (_name, c) => {
        expect(wouldCycle(membersOf(c.members), c.id, c.parentId)).toBe(c.result);
    });

    test.each(data.reparentOnDelete.map((c) => [c.name, c] as const))('reparentOnDelete: %s', (_name, c) => {
        const members = membersOf(c.members);
        expect(reparentOnDelete(members, c.id)).toEqual(c.placements);
        expect(shape(buildOrgTree(removeMember(members, c.id)))).toEqual(c.shape);
    });

    test.each(data.placementsError.map((c) => [c.name, c] as const))('placementsError: %s', (_name, c) => {
        expect(placementsError(membersOf(c.members), c.placements)).toBe(c.error);
    });
});
