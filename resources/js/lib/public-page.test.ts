import { describe, expect, it } from 'vitest';
import { isPublicPage } from './public-page';

describe('isPublicPage', () => {
    it('accepts the public pages a visitor navigates to', () => {
        for (const path of ['/', '/posts', '/posts/enrollment-open', '/departments/cit', '/downloads', '/contact']) {
            expect(isPublicPage(path), path).toBe(true);
        }
    });

    it('rejects private routes, assets, API and feeds, whose responses a hover prefetch could not reuse', () => {
        for (const path of [
            '/login',
            '/forgot-password',
            '/admin/posts',
            '/dashboard',
            '/settings/profile',
            '/api/auth/session',
            '/_actions/contact.send',
            '/storage/posts/a.webp',
            '/build/assets/app.js',
            '/rss.xml',
            '/sitemap.xml',
        ]) {
            expect(isPublicPage(path), path).toBe(false);
        }
    });
});
