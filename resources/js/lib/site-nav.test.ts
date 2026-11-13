import { describe, expect, it } from 'vitest';
import { SITEMAP_PAGES, STATIC_PATHS } from './site-nav';

describe('paths', () => {
    it('keeps the sitemap pages valid link targets', () => {
        for (const path of SITEMAP_PAGES) expect(STATIC_PATHS).toContain(path);
    });
});
