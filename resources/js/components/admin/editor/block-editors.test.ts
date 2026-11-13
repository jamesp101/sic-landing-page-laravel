import { expect, test } from 'vitest';
import { BLOCK_TYPES } from '@/lib/blocks/registry';
import { EDITOR_BLOCKS } from './block-editors';

test('every Block type has an editor entry', () => {
    expect(Object.keys(EDITOR_BLOCKS).sort()).toEqual([...BLOCK_TYPES].sort());
});
