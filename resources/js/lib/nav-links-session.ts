/**
 * Navigation link editing session: the one place that owns the Navigation links while their order, nesting,
 * label and visibility are edited. Free of Svelte so it is unit-tested with an in-memory port.
 *
 * - Every edit is optimistic on a {@link createTreeEditSession} (queue, rollback on a rejected save, undo history).
 *   Renames and visibility changes (`patch`) never enter undo history.
 * - Moves (move up/down, indent, outdent) and a finished drag are proposed as a whole new tree; the session
 *   validates it, turns it into a reorder payload and saves it.
 * - A drag in progress is a draft shown by `snapshot().tree`; it is never saved until `drop`.
 */

import {
    cloneTree,
    findNode,
    indent,
    locate,
    moveDown,
    moveUp,
    navTreeOps,
    outdent,
    proposeNavTree,
    type NavNode,
    type NavPatch,
    type ReorderItem,
} from './nav-tree';
import { createTreeEditSession, type EditResult, type SaveOutcome, type TreeEditSnapshot } from './tree-edit-session';

/** The fields `actions.navlinks.update` takes. */
export interface NavLinkUpdate {
    id: number;
    label: string;
    url: string;
    order: number;
    isActive: boolean;
    parentId: number | null;
}

/** The server, as the Navigation link editing session sees it. */
export interface NavLinksPort {
    reorder(items: ReorderItem[]): Promise<SaveOutcome>;
    update(link: NavLinkUpdate): Promise<SaveOutcome>;
}

export type NavMove = 'up' | 'down' | 'indent' | 'outdent';

/** An edit result, or the rule that refused a proposed tree. */
export type NavResult = EditResult | { status: 'invalid'; message: string };

export interface NavLinksSession {
    /** `tree` is what to draw: the optimistic tree, or the drag in progress. */
    snapshot(): TreeEditSnapshot<NavNode[]>;
    /** Moves a link one step; `noop` when it cannot move that way. */
    move(id: number, move: NavMove): Promise<NavResult>;
    /** Shows a drag in progress: the top-level order. */
    dragTops(items: NavNode[]): void;
    /** Shows a drag in progress: the sub-links of one top-level link. */
    dragChildren(topId: number, items: NavNode[]): void;
    /** Ends the drag: saves the drafted tree, or drops the draft with the rule's message when it is not allowed. */
    drop(): Promise<NavResult>;
    /** Saves a label or visibility change; kept out of undo history. */
    patch(id: number, patch: NavPatch): Promise<EditResult>;
    undo(): Promise<EditResult>;
    redo(): Promise<EditResult>;
}

export const LINK_GONE = 'This link no longer exists.';
const UPDATE_FAILED = 'The link could not be updated.';
const ORDER_FAILED = 'The order could not be saved.';

const MOVES: Record<NavMove, (tree: NavNode[], id: number) => NavNode[] | null> = {
    up: moveUp,
    down: moveDown,
    indent,
    outdent,
};

export function createNavLinksSession(options: {
    links: NavNode[];
    port: NavLinksPort;
    onChange?: (snapshot: TreeEditSnapshot<NavNode[]>) => void;
}): NavLinksSession {
    const { port, onChange } = options;
    let draft: NavNode[] | null = null;
    let lastTree: NavNode[] | null = null;

    const tree = createTreeEditSession({
        tree: cloneTree(options.links),
        ops: navTreeOps,
        fallbackMessage: ORDER_FAILED,
        onChange: () => {
            // A tree the session itself changed replaces whatever drag was being shown.
            const next = tree.snapshot().tree;
            if (next !== lastTree) draft = null;
            emit();
        },
        port: {
            async save(change, confirmed) {
                if (change.kind === 'tree') return port.reorder(change.items);
                const pos = locate(confirmed, change.id);
                if (!pos) return { ok: false, message: LINK_GONE };
                const parent = pos.parentIndex === null ? null : confirmed[pos.parentIndex];
                const node = parent ? parent.children[pos.index] : confirmed[pos.index];
                const outcome = await port.update({
                    id: node.id,
                    label: change.patch.label ?? node.label,
                    url: node.url,
                    order: node.order,
                    isActive: change.patch.isActive ?? node.isActive,
                    parentId: parent?.id ?? null,
                });
                return outcome.ok ? outcome : { ok: false, message: outcome.message || UPDATE_FAILED };
            },
        },
    });
    lastTree = tree.snapshot().tree;

    function snapshot(): TreeEditSnapshot<NavNode[]> {
        const inner = tree.snapshot();
        lastTree = inner.tree;
        return draft ? { ...inner, tree: draft } : inner;
    }

    function emit() {
        onChange?.(snapshot());
    }

    function shown(): NavNode[] {
        return draft ?? tree.snapshot().tree;
    }

    /** Proposes `next` as the new tree; the session saves it, or the rule's message says why it cannot be. */
    function propose(next: NavNode[]): Promise<NavResult> {
        draft = null;
        const proposal = proposeNavTree(tree.snapshot().tree, next);
        if ('error' in proposal) {
            emit();
            return Promise.resolve({ status: 'invalid', message: proposal.error });
        }
        return tree.edit(proposal.change);
    }

    return {
        snapshot,

        async move(id, move) {
            const next = MOVES[move](tree.snapshot().tree, id);
            return next ? propose(next) : { status: 'noop' };
        },

        dragTops(items) {
            draft = items;
            emit();
        },

        dragChildren(topId, items) {
            const current = shown();
            if (!findNode(current, topId)) return;
            draft = current.map((top) => (top.id === topId ? { ...top, children: items } : top));
            emit();
        },

        async drop() {
            if (!draft) return { status: 'noop' };
            return propose(draft);
        },

        patch: (id, patch) => tree.edit({ kind: 'patch', id, patch }, { record: false }),
        undo: () => tree.undo(),
        redo: () => tree.redo(),
    };
}
