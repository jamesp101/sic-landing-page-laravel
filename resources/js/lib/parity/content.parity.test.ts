import { describe, expect, test } from 'vitest';
import { hasAlbum, type ContentCheck, parseContent, renderContent, searchTextOf } from '../blocks/content';
import { renderBlocks } from '../blocks/render';
import { parseBlockJson } from '../blocks/schema';
import { fixture, label, largeInput } from './fixtures';

interface ContentCase {
    name: string;
    input: string;
    format: 'empty' | 'blocks' | 'markdown';
    html: string;
    text: string;
    excerpt: string;
    check: ContentCheck;
    searchText: string;
    hasAlbum: boolean;
}

interface EditorCase {
    name: string;
    input: string;
    html: string;
}

// Mirrors tests/Unit/Content/ContentParityTest.php.
describe('content.json', () => {
    const cases = fixture<ContentCase[]>('Content', 'content');
    test.each(cases.map((c, i) => [label(c, i), c] as const))('%s', (_name, c) => {
        const input = largeInput(c);
        const parsed = parseContent(input);
        expect(parsed.format).toBe(c.format);
        expect(parsed.html()).toBe(c.html);
        expect(parsed.text()).toBe(c.text);
        expect(parsed.excerpt()).toBe(c.excerpt);
        expect(parsed.check()).toEqual(c.check);
        expect(searchTextOf(input)).toBe(c.searchText);
        expect(hasAlbum(parsed.html())).toBe(c.hasAlbum);
        expect(renderContent(input)).toBe(c.html);
    });
});

describe('editor.json', () => {
    const cases = fixture<EditorCase[]>('Content', 'editor');
    test.each(cases.map((c, i) => [label(c, i), c] as const))('%s', (_name, c) => {
        const { result } = parseBlockJson(c.input);
        expect(result.ok).toBe(true);
        if (!result.ok) return;
        expect(renderBlocks(result.document.blocks, { editor: true })).toBe(c.html);
    });
});
