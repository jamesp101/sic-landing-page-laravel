import { describe, expect, test } from 'vitest';
import { isPublicPage } from '../public-page';
import { fixture } from './fixtures';

interface RouteClassCase {
    path: string;
    publicPage: boolean;
    private: boolean;
    asset: boolean;
}

describe('isPublicPage matches the server route classes (RequestPolicyRules)', () => {
    const { cases } = fixture<{ cases: RouteClassCase[] }>('Platform', 'route-classes');
    test.each(cases.map((c): [string, RouteClassCase] => [c.path, c]))('%s', (_path, c) => {
        expect(isPublicPage(c.path)).toBe(c.publicPage);
    });
});
