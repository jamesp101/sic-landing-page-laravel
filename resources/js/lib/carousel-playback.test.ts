import { describe, expect, it } from 'vitest';
import {
    autoplay,
    canAutoplay,
    classifySwipe,
    keyTarget,
    preloadWindow,
    showPill,
    startsSwipe,
    videoAllowed,
    videoRunning,
    wrapIndex,
    type PlaybackInputs,
} from './carousel-playback';

const base: PlaybackInputs = {
    mounted: true,
    slideCount: 3,
    hasVideo: false,
    reducedMotion: false,
    saveData: false,
    userPaused: false,
    hovering: false,
    focused: false,
    inView: true,
    pageVisible: true,
};
const with_ = (over: Partial<PlaybackInputs>): PlaybackInputs => ({ ...base, ...over });
const noKeys = { altKey: false, ctrlKey: false, metaKey: false, shiftKey: false };

describe('autoplay', () => {
    it('runs when every gate is open', () => expect(autoplay(base)).toBe(true));

    it.each([
        { mounted: false },
        { slideCount: 1 },
        { reducedMotion: true },
        { userPaused: true },
        { hovering: true },
        { focused: true },
        { inView: false },
        { pageVisible: false },
    ])('stops for %o', (over) => expect(autoplay(with_(over))).toBe(false));

    it('progress timing ignores hover and focus', () => {
        expect(canAutoplay(with_({ hovering: true, focused: true, userPaused: true }))).toBe(true);
    });
});

describe('background video', () => {
    it('reduced motion beats everything', () => {
        expect(videoAllowed(with_({ reducedMotion: true }))).toBe(false);
        expect(videoRunning(with_({ reducedMotion: true }))).toBe(false);
    });

    it('save data blocks it', () => expect(videoAllowed(with_({ saveData: true }))).toBe(false));

    it('needs mount', () => expect(videoAllowed(with_({ mounted: false }))).toBe(false));

    it('runs unless paused, off screen or hidden; hover and focus do not matter', () => {
        expect(videoRunning(with_({ hovering: true, focused: true }))).toBe(true);
        expect(videoRunning(with_({ userPaused: true }))).toBe(false);
        expect(videoRunning(with_({ inView: false }))).toBe(false);
        expect(videoRunning(with_({ pageVisible: false }))).toBe(false);
    });
});

describe('pill', () => {
    it('shows for a multi-Slide Carousel even with reduced motion', () => {
        expect(showPill(with_({ reducedMotion: true }))).toBe(true);
    });

    it('single Slide needs a playable Background video', () => {
        const single = { slideCount: 1 };
        expect(showPill(with_(single))).toBe(false);
        expect(showPill(with_({ ...single, hasVideo: true }))).toBe(true);
        expect(showPill(with_({ ...single, hasVideo: true, reducedMotion: true }))).toBe(false);
        expect(showPill(with_({ ...single, hasVideo: true, saveData: true }))).toBe(false);
    });

    it('hidden before mount', () => expect(showPill(with_({ mounted: false }))).toBe(false));
});

describe('wrapIndex', () => {
    it('wraps at both ends', () => {
        expect(wrapIndex(-1, 4)).toBe(3);
        expect(wrapIndex(4, 4)).toBe(0);
        expect(wrapIndex(2, 4)).toBe(2);
    });

    it('single Slide stays at 0', () => {
        expect(wrapIndex(1, 1)).toBe(0);
        expect(wrapIndex(-1, 1)).toBe(0);
    });
});

describe('keyTarget', () => {
    it('maps navigation keys', () => {
        expect(keyTarget('ArrowLeft', noKeys, 2, 5)).toBe(1);
        expect(keyTarget('ArrowRight', noKeys, 2, 5)).toBe(3);
        expect(keyTarget('Home', noKeys, 2, 5)).toBe(0);
        expect(keyTarget('End', noKeys, 2, 5)).toBe(4);
    });

    it('leaves arrows unwrapped for the caller', () => {
        expect(keyTarget('ArrowLeft', noKeys, 0, 5)).toBe(-1);
        expect(keyTarget('ArrowRight', noKeys, 4, 5)).toBe(5);
    });

    it('ignores other keys, modifiers and single-Slide Carousels', () => {
        expect(keyTarget('a', noKeys, 0, 5)).toBeUndefined();
        for (const mod of ['altKey', 'ctrlKey', 'metaKey', 'shiftKey'] as const) {
            expect(keyTarget('ArrowRight', { ...noKeys, [mod]: true }, 0, 5)).toBeUndefined();
        }
        expect(keyTarget('ArrowRight', noKeys, 0, 1)).toBeUndefined();
    });
});

describe('swipe', () => {
    it('starts only for primary non-mouse pointers on a multi-Slide Carousel', () => {
        expect(startsSwipe(3, 'touch', true)).toBe(true);
        expect(startsSwipe(3, 'pen', true)).toBe(true);
        expect(startsSwipe(3, 'mouse', true)).toBe(false);
        expect(startsSwipe(3, 'touch', false)).toBe(false);
        expect(startsSwipe(1, 'touch', true)).toBe(false);
    });

    it('needs 48 px of horizontal travel', () => {
        expect(classifySwipe(-47, 0)).toBeNull();
        expect(classifySwipe(-48, 0)).toBe('next');
        expect(classifySwipe(48, 0)).toBe('previous');
        expect(classifySwipe(47, 0)).toBeNull();
    });

    it('needs 1.5x horizontal bias', () => {
        expect(classifySwipe(-90, 60)).toBe('next');
        expect(classifySwipe(-90, 61)).toBeNull();
        expect(classifySwipe(90, -61)).toBeNull();
        expect(classifySwipe(60, 0)).toBe('previous');
    });
});

describe('preloadWindow', () => {
    it('is current, next, previous', () => expect(preloadWindow(2, 5)).toEqual([2, 3, 1]));

    it('wraps at both ends', () => {
        expect(preloadWindow(0, 5)).toEqual([0, 1, 4]);
        expect(preloadWindow(4, 5)).toEqual([4, 0, 3]);
    });

    it('single Slide yields only itself', () => expect(new Set(preloadWindow(0, 1))).toEqual(new Set([0])));
});
