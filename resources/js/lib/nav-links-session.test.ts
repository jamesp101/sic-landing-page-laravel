import { describe, expect, it } from 'vitest';
import type { NavNode, ReorderItem } from './nav-tree';
import { createNavLinksSession, type NavLinksPort, type NavLinkUpdate } from './nav-links-session';
import type { SaveOutcome } from './tree-edit-session';

const link = (id: number, order: number, children: NavNode[] = [], over: Partial<NavNode> = {}): NavNode => ({
    id,
    label: `L${id}`,
    url: `/l${id}`,
    isActive: true,
    order,
    children,
    ...over,
});

// 1 ── 2
//   └─ 3
// 4
const links = () => [link(1, 1, [link(2, 1), link(3, 2)]), link(4, 2)];

function fakePort(failing: 'reorder' | 'update' | null = null) {
    const reorders: ReorderItem[][] = [];
    const updates: NavLinkUpdate[] = [];
    const port: NavLinksPort = {
        async reorder(items): Promise<SaveOutcome> {
            reorders.push(items);
            return failing === 'reorder' ? { ok: false, message: 'No reorder.' } : { ok: true };
        },
        async update(payload): Promise<SaveOutcome> {
            updates.push(payload);
            return failing === 'update' ? { ok: false } : { ok: true };
        },
    };
    return { port, reorders, updates };
}

const ids = (tree: NavNode[]) => tree.map((top) => [top.id, ...top.children.map((c) => c.id)].join('>'));

describe('moves', () => {
    it('saves a reorder payload with parent and order for every link', async () => {
        const { port, reorders } = fakePort();
        const session = createNavLinksSession({ links: links(), port });

        expect(await session.move(4, 'up')).toEqual({ status: 'saved' });

        expect(reorders).toEqual([
            [
                { id: 4, parentId: null, order: 1 },
                { id: 1, parentId: null, order: 2 },
                { id: 2, parentId: 1, order: 1 },
                { id: 3, parentId: 1, order: 2 },
            ],
        ]);
        expect(ids(session.snapshot().tree)).toEqual(['4', '1>2>3']);
    });

    it('indents under the link above and outdents after its parent', async () => {
        const { port, reorders } = fakePort();
        const session = createNavLinksSession({ links: links(), port });

        await session.move(4, 'indent');
        expect(reorders[0]).toContainEqual({ id: 4, parentId: 1, order: 3 });
        await session.move(2, 'outdent');
        expect(ids(session.snapshot().tree)).toEqual(['1>3>4', '2']);
    });

    it('is a noop where the link cannot move', async () => {
        const { port, reorders } = fakePort();
        const session = createNavLinksSession({ links: links(), port });

        expect(await session.move(1, 'up')).toEqual({ status: 'noop' });
        expect(await session.move(1, 'indent')).toEqual({ status: 'noop' });
        expect(await session.move(4, 'outdent')).toEqual({ status: 'noop' });
        expect(reorders).toEqual([]);
    });

    it('rolls back to the confirmed tree when the save is rejected', async () => {
        const { port } = fakePort('reorder');
        const session = createNavLinksSession({ links: links(), port });

        const pending = session.move(4, 'up');
        expect(ids(session.snapshot().tree)).toEqual(['4', '1>2>3']);
        expect(await pending).toMatchObject({ status: 'reverted', message: 'No reorder.' });
        expect(ids(session.snapshot().tree)).toEqual(['1>2>3', '4']);
        expect(session.snapshot().canUndo).toBe(false);
    });

    it('undoes and redoes a move, saving each as a reorder', async () => {
        const { port, reorders } = fakePort();
        const session = createNavLinksSession({ links: links(), port });

        await session.move(4, 'up');
        await session.undo();
        expect(ids(session.snapshot().tree)).toEqual(['1>2>3', '4']);
        await session.redo();
        expect(ids(session.snapshot().tree)).toEqual(['4', '1>2>3']);
        expect(reorders).toHaveLength(3);
    });
});

describe('drag', () => {
    it('shows the draft without saving, then saves it on drop', async () => {
        const { port, reorders } = fakePort();
        const session = createNavLinksSession({ links: links(), port });
        const [one, four] = session.snapshot().tree;

        session.dragTops([four, one]);
        expect(ids(session.snapshot().tree)).toEqual(['4', '1>2>3']);
        expect(reorders).toEqual([]);

        expect(await session.drop()).toEqual({ status: 'saved' });
        expect(reorders).toHaveLength(1);
        expect(reorders[0][0]).toEqual({ id: 4, parentId: null, order: 1 });
    });

    it('moves a link between zones across two considers', async () => {
        const { port, reorders } = fakePort();
        const session = createNavLinksSession({ links: links(), port });
        const [one, four] = session.snapshot().tree;

        session.dragTops([one]);
        session.dragChildren(1, [...one.children, four]);
        expect(await session.drop()).toEqual({ status: 'saved' });
        expect(reorders[0]).toContainEqual({ id: 4, parentId: 1, order: 3 });
    });

    it('refuses a link dropped under a sub-link and goes back to the saved tree', async () => {
        const { port, reorders } = fakePort();
        const session = createNavLinksSession({ links: links(), port });
        const [one, four] = session.snapshot().tree;

        session.dragTops([one]);
        session.dragChildren(1, [{ ...one.children[0], children: [four] }, one.children[1]]);
        expect(await session.drop()).toMatchObject({ status: 'invalid' });
        expect(ids(session.snapshot().tree)).toEqual(['1>2>3', '4']);
        expect(reorders).toEqual([]);
    });

    it('drops an unchanged draft as a noop', async () => {
        const { port, reorders } = fakePort();
        const session = createNavLinksSession({ links: links(), port });

        session.dragTops(session.snapshot().tree);
        expect(await session.drop()).toEqual({ status: 'noop' });
        expect(reorders).toEqual([]);
    });
});

describe('patch', () => {
    it('sends the full update from the confirmed link, changing only the patched field', async () => {
        const { port, updates } = fakePort();
        const session = createNavLinksSession({ links: links(), port });

        expect(await session.patch(3, { label: 'Renamed' })).toEqual({ status: 'saved' });
        await session.patch(3, { isActive: false });

        expect(updates).toEqual([
            { id: 3, label: 'Renamed', url: '/l3', order: 2, isActive: true, parentId: 1 },
            { id: 3, label: 'Renamed', url: '/l3', order: 2, isActive: false, parentId: 1 },
        ]);
    });

    it('sends parentId null for a top-level link', async () => {
        const { port, updates } = fakePort();
        const session = createNavLinksSession({ links: links(), port });

        await session.patch(4, { label: 'X' });
        expect(updates[0]).toMatchObject({ id: 4, parentId: null, order: 2 });
    });

    it('never enters undo history', async () => {
        const { port } = fakePort();
        const session = createNavLinksSession({ links: links(), port });

        await session.patch(4, { label: 'X' });
        expect(session.snapshot().canUndo).toBe(false);
        await session.move(4, 'up');
        await session.patch(1, { label: 'Y' });
        await session.undo();
        expect(ids(session.snapshot().tree)).toEqual(['1>2>3', '4']);
        expect(session.snapshot().tree[0].label).toBe('Y');
    });

    it('is a noop when nothing changes, and reverts with a message when rejected', async () => {
        const { port, updates } = fakePort('update');
        const session = createNavLinksSession({ links: links(), port });

        expect(await session.patch(4, { label: 'L4' })).toEqual({ status: 'noop' });
        expect(updates).toEqual([]);
        expect(await session.patch(4, { label: 'X' })).toMatchObject({
            status: 'reverted',
            message: 'The link could not be updated.',
        });
        expect(session.snapshot().tree[1].label).toBe('L4');
    });

    it('ignores a link that is not in the tree', async () => {
        const { port } = fakePort();
        const session = createNavLinksSession({ links: links(), port });

        expect(await session.patch(99, { label: 'X' })).toEqual({ status: 'noop' });
    });
});
