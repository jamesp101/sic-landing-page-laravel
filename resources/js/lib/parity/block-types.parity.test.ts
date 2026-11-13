import { expect, test } from 'vitest';
import { BLOCK_TYPES } from '../blocks/registry';
import { fixture } from './fixtures';

test('the TS registry lists the Block types of the fixture, in palette order', () => {
    expect(BLOCK_TYPES).toEqual(fixture<{ types: string[] }>('Content', 'block-types').types);
});
