import { describe, expect, it } from 'vitest';
import { contrastRatio, parseRgb } from './contrast';

describe('parseRgb', () => {
    it('reads the computed-style syntaxes', () => {
        expect(parseRgb('rgb(10, 46, 110)')).toEqual([10, 46, 110, 1]);
        expect(parseRgb('rgba(0, 0, 0, 0)')).toEqual([0, 0, 0, 0]);
        expect(parseRgb('rgb(255 255 255 / 0.08)')).toEqual([255, 255, 255, 0.08]);
        expect(parseRgb('rgb(255 255 255 / 50%)')).toEqual([255, 255, 255, 0.5]);
    });

    it('returns null for other colour syntaxes', () => {
        expect(parseRgb('oklab(0.99 0 0 / 0.9)')).toBeNull();
        expect(parseRgb('transparent')).toBeNull();
    });
});

describe('contrastRatio', () => {
    it('is 21 for black on white and 1 for equal colours, in either order', () => {
        expect(contrastRatio([0, 0, 0, 1], [255, 255, 255, 1])).toBeCloseTo(21, 5);
        expect(contrastRatio([255, 255, 255, 1], [0, 0, 0, 1])).toBeCloseTo(21, 5);
        expect(contrastRatio([10, 46, 110, 1], [10, 46, 110, 1])).toBe(1);
    });

    it('matches a known pair', () => {
        // #767676 on white is the classic lightest grey that passes AA (4.54:1).
        expect(contrastRatio([118, 118, 118, 1], [255, 255, 255, 1])).toBeCloseTo(4.54, 2);
    });
});
