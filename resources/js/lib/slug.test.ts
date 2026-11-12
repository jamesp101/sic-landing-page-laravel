import { describe, expect, it } from 'vitest';
import { slugify } from './slug';

describe('slugify', () => {
    it('lower-cases and joins words with dashes', () => {
        expect(slugify('Enrollment Details 2026')).toBe('enrollment-details-2026');
    });

    it('collapses punctuation, underscores and repeated separators', () => {
        expect(slugify('  Hello,   World!! _ again ')).toBe('hello-world-again');
    });

    it('strips accents', () => {
        expect(slugify('Café Señor')).toBe('cafe-senor');
    });

    it('returns an empty string when nothing usable remains', () => {
        expect(slugify('!!! ???')).toBe('');
    });
});
