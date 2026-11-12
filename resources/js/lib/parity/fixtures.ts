import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

/**
 * Fixtures under `tests/Unit/{Content,Carousel}/fixtures` are the contract between the TS modules and their PHP twins:
 * PHPUnit feeds them to the PHP side, the `*.parity.test.ts` suites beside this file feed them to the TS side.
 */
const root = fileURLToPath(new URL('../../../../tests/Unit/', import.meta.url));

export function fixture<T>(directory: 'Content' | 'Carousel', name: string): T {
    return JSON.parse(readFileSync(`${root}${directory}/fixtures/${name}.json`, 'utf8')) as T;
}

/** Inputs too big to store in a fixture file are rebuilt (same rule as `FixtureTestCase::input`). */
export function largeInput(testCase: { name?: string; input: string }): string {
    if (testCase.input !== '@large') return testCase.input;
    switch (testCase.name) {
        case 'too large':
            return `{"type":"sic-blocks","version":1,"blocks":[],"pad":"${'x'.repeat(400_000)}"}`;
        case 'markdown too big':
            return JSON.stringify({
                type: 'sic-blocks',
                version: 1,
                blocks: [{ id: 'big', type: 'markdown', props: { source: 'x'.repeat(100_001) } }],
            });
        default:
            throw new Error(`No rebuild rule for large fixture input "${testCase.name}"`);
    }
}

export const label = (testCase: object, index: number): string =>
    `${'name' in testCase && typeof testCase.name === 'string' ? testCase.name : index} #${index}`;
