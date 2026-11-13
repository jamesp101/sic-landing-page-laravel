import { describe, expect, test } from 'vitest';
import type { NavLinkView } from '../content-types';
import {
    DEFAULT_NAV_LINKS,
    departmentHref,
    footerItems,
    headerItems,
    postHref,
    type FooterLink,
    type NavItem,
} from '../site-nav';
import { fixture } from './fixtures';

interface SiteNavCase {
    name: string;
    navLinks: NavLinkView[];
    departments: { name: string; slug: string }[];
    header: NavItem[];
    footer: { departments: FooterLink[]; quickLinks: FooterLink[] };
}

interface SiteNavFixture {
    links: { departmentHref: [string, string][]; postHref: [string, string][] };
    defaultNavLinks: { label: string; url: string }[];
    cases: SiteNavCase[];
}

const data = fixture<SiteNavFixture>('Site', 'site-nav');

describe('site-nav matches the server (App\\Site\\SiteNav)', () => {
    test.each(data.cases.map((c): [string, SiteNavCase] => [c.name, c]))('%s', (_name, c) => {
        expect(headerItems(c.navLinks, c.departments)).toEqual(c.header);
        expect(footerItems(c.departments)).toEqual(c.footer);
    });

    test('default menu and hrefs', () => {
        expect(DEFAULT_NAV_LINKS).toEqual(data.defaultNavLinks);
        for (const [slug, href] of data.links.departmentHref) expect(departmentHref(slug)).toBe(href);
        for (const [slug, href] of data.links.postHref) expect(postHref(slug)).toBe(href);
    });
});
