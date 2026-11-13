import { describe, expect, test } from 'vitest';
import { ACTION_NAMES } from '../action-names';
import { actions } from '../actions';
import { fixture } from './fixtures';

describe('actions client names match ActionRegistry (via the fixture)', () => {
    test('ACTION_NAMES lists exactly the fixture', () => {
        expect(ACTION_NAMES).toEqual(fixture<{ actions: Record<string, string[]> }>('Platform', 'actions').actions);
    });

    test('a misspelled module or action does not type-check', () => {
        // Only checked by `pnpm run check`: the directives fail there if the names type-check.
        const unused = () => {
            // @ts-expect-error unknown action
            actions.posts.craete({});
            // @ts-expect-error unknown module
            actions.post.create({});
        };
        expect(unused).toBeTypeOf('function');
    });
});
