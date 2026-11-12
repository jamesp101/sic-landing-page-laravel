import { describe, expect, test } from 'vitest';
import { formatEventDay, formatEventTime, parseEventDate, type EventDate } from '../blocks/event-date';
import { escapeInline, inlineToHtml, inlineToText, paragraphsOf } from '../blocks/inline';
import { isExternalHref, parseVideoUrl, safeHref, safeImageSrc, type VideoSource } from '../blocks/urls';
import type { FileKind } from '../content-types';
import { extensionOf, fileKind } from '../files';
import { formatDate, formatDateRange, formatFileSize, type FormatDateOptions } from '../format';
import { getInitials } from '../initials';
import { parseMarkdown } from '../markdown';
import { stripMarkdown } from '../markdown-strip';
import { pageWindow } from '../pagination';
import { fixture, label } from './fixtures';

const each = <T extends object>(cases: readonly T[]): [string, T][] =>
    cases.map((c, i): [string, T] => [label(c, i), c]);
const rows = <T>(cases: readonly T[]): [string, T][] =>
    cases.map((c, i): [string, T] => [`${i} ${JSON.stringify(c)}`, c]);

interface MarkdownCase {
    name: string;
    input: string;
    html: string;
    text: string;
}
interface UrlCase {
    input: string;
    safeHref: string | null;
    safeImageSrc: string | null;
    isExternal: boolean | null;
    video: VideoSource | null;
}
interface VideoCase {
    input: string;
    video: VideoSource | null;
}
interface InlineCase {
    input: string;
    html: string;
    text: string;
    escaped: string;
    paragraphs: string[];
}
interface ParagraphCase {
    input: string;
    paragraphs: string[];
}
interface EventDateCase {
    input: string;
    date: EventDate | null;
    day: string;
    time: string;
    key: number;
}
interface DateCase {
    input: string;
    opts: FormatDateOptions;
    out: string;
}
interface RangeCase {
    start: string;
    end: string;
    opts: FormatDateOptions;
    out: string;
}
interface SizeCase {
    input: number | 'NaN';
    out: string;
}
interface InitialsCase {
    input: string;
    out: string;
}
interface PaginationCase {
    current: number;
    count: number;
    out: (number | null)[];
}
interface FileCase {
    input: string;
    ext: string;
    kind: FileKind;
    kindDot: FileKind;
}

// Mirrors tests/Unit/Content/HelpersParityTest.php.
describe('markdown.json', () => {
    test.each(each(fixture<MarkdownCase[]>('Content', 'markdown')))('%s', (_n, c) => {
        expect(parseMarkdown(c.input)).toBe(c.html);
        expect(stripMarkdown(c.input)).toBe(c.text);
    });
});

describe('urls.json', () => {
    test.each(each(fixture<UrlCase[]>('Content', 'urls')))('%s', (_n, c) => {
        expect(safeHref(c.input)).toBe(c.safeHref);
        expect(safeImageSrc(c.input)).toBe(c.safeImageSrc);
        if (c.safeHref !== null) expect(isExternalHref(c.safeHref)).toBe(c.isExternal);
        expect(parseVideoUrl(c.input)).toEqual(c.video);
    });
});

describe('video-urls.json', () => {
    test.each(each(fixture<VideoCase[]>('Content', 'video-urls')))('%s', (_n, c) => {
        expect(parseVideoUrl(c.input)).toEqual(c.video);
    });
});

describe('inline.json', () => {
    test.each(each(fixture<InlineCase[]>('Content', 'inline')))('%s', (_n, c) => {
        expect(inlineToHtml(c.input)).toBe(c.html);
        expect(inlineToText(c.input)).toBe(c.text);
        expect(escapeInline(c.input)).toBe(c.escaped);
        expect(paragraphsOf(c.input)).toEqual(c.paragraphs);
    });
});

describe('paragraphs.json', () => {
    test.each(each(fixture<ParagraphCase[]>('Content', 'paragraphs')))('%s', (_n, c) => {
        expect(paragraphsOf(c.input)).toEqual(c.paragraphs);
    });
});

describe('event-date.json', () => {
    test.each(each(fixture<EventDateCase[]>('Content', 'event-date')))('%s', (_n, c) => {
        const date = parseEventDate(c.input);
        expect(date).toEqual(c.date);
        if (date !== null) {
            expect(formatEventDay(date)).toBe(c.day);
            expect(formatEventTime(date)).toBe(c.time);
        }
    });
});

// Mirrors tests/Unit/Support/SupportParityTest.php.
describe('format.json', () => {
    const format = fixture<{ dates: DateCase[]; ranges: RangeCase[]; sizes: SizeCase[] }>('Content', 'format');
    test.each(rows(format.dates))('date %s', (_n, c) => {
        expect(formatDate(c.input, c.opts)).toBe(c.out);
    });
    test.each(rows(format.ranges))('range %s', (_n, c) => {
        expect(formatDateRange(c.start, c.end, c.opts)).toBe(c.out);
    });
    test.each(rows(format.sizes))('size %s', (_n, c) => {
        expect(formatFileSize(c.input === 'NaN' ? Number.NaN : c.input)).toBe(c.out);
    });
});

describe('initials.json', () => {
    test.each(rows(fixture<InitialsCase[]>('Content', 'initials')))('%s', (_n, c) => {
        expect(getInitials(c.input)).toBe(c.out);
    });
});

describe('pagination.json', () => {
    test.each(rows(fixture<PaginationCase[]>('Content', 'pagination')))('%s', (_n, c) => {
        expect(pageWindow(c.current, c.count)).toEqual(c.out);
    });
});

describe('files.json', () => {
    test.each(rows(fixture<FileCase[]>('Content', 'files')))('%s', (_n, c) => {
        expect(extensionOf(c.input)).toBe(c.ext);
        expect(fileKind(c.ext)).toBe(c.kind);
        expect(fileKind(`.${c.input}`)).toBe(c.kindDot);
    });
});
