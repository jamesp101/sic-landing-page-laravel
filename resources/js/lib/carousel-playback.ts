/**
 * Playback rules of the hero Carousel: when it autoplays, when a Background video runs,
 * how Slide indices wrap, and how keys and swipes map to Slides. Pure; HeroCarousel only wires DOM events to these.
 */

/** Time each Slide stays up while autoplaying; the active dot's progress bar runs for this long. */
export const SLIDE_MS = 7000;
/** Minimum horizontal travel for a touch swipe. */
const SWIPE_PX = 48;
/** A swipe must be this many times wider than tall, so vertical scrolling is not read as a swipe. */
const SWIPE_HORIZONTAL_BIAS = 1.5;

export type PlaybackInputs = {
    mounted: boolean;
    slideCount: number;
    /** Some Slide has a Background video. */
    hasVideo: boolean;
    reducedMotion: boolean;
    saveData: boolean;
    userPaused: boolean;
    hovering: boolean;
    focused: boolean;
    inView: boolean;
    pageVisible: boolean;
};

export function isMultiple(slideCount: number): boolean {
    return slideCount > 1;
}

export function canAutoplay(p: PlaybackInputs): boolean {
    return p.mounted && isMultiple(p.slideCount) && !p.reducedMotion;
}

/** Background videos only start for visitors who allow motion and data use; everyone else keeps the photo (the poster). */
export function videoAllowed(p: PlaybackInputs): boolean {
    return p.mounted && !p.reducedMotion && !p.saveData;
}

export function videoRunning(p: PlaybackInputs): boolean {
    return videoAllowed(p) && !p.userPaused && p.inView && p.pageVisible;
}

/** The play/pause pill shows for a Carousel with several Slides, or for a single Slide whose Background video may play. */
export function showPill(p: PlaybackInputs): boolean {
    return p.mounted && (isMultiple(p.slideCount) || (videoAllowed(p) && p.hasVideo));
}

export function autoplay(p: PlaybackInputs): boolean {
    return canAutoplay(p) && !p.userPaused && !p.hovering && !p.focused && p.inView && p.pageVisible;
}

/** Wraps a Slide index around both ends of the Carousel. */
export function wrapIndex(index: number, slideCount: number): number {
    return (index + slideCount) % slideCount;
}

type KeyModifiers = Pick<KeyboardEvent, 'altKey' | 'ctrlKey' | 'metaKey' | 'shiftKey'>;

/** Slide index a key navigates to (unwrapped), or undefined when the key is not a Carousel key. */
export function keyTarget(
    key: string,
    modifiers: KeyModifiers,
    current: number,
    slideCount: number,
): number | undefined {
    if (!isMultiple(slideCount) || modifiers.altKey || modifiers.ctrlKey || modifiers.metaKey || modifiers.shiftKey)
        return undefined;
    switch (key) {
        case 'ArrowLeft':
            return current - 1;
        case 'ArrowRight':
            return current + 1;
        case 'Home':
            return 0;
        case 'End':
            return slideCount - 1;
        default:
            return undefined;
    }
}

/** A swipe starts only on a primary touch or pen pointer in a Carousel with several Slides. */
export function startsSwipe(slideCount: number, pointerType: string, isPrimary: boolean): boolean {
    return isMultiple(slideCount) && pointerType !== 'mouse' && isPrimary;
}

/** Swiping left shows the next Slide, right the previous one; short or mostly vertical travel is no swipe. */
export function classifySwipe(dx: number, dy: number): 'next' | 'previous' | null {
    if (Math.abs(dx) < SWIPE_PX || Math.abs(dx) < Math.abs(dy) * SWIPE_HORIZONTAL_BIAS) return null;
    return dx < 0 ? 'next' : 'previous';
}

/** Slide indices whose photo may load: the current Slide, the next, then the previous. */
export function preloadWindow(current: number, slideCount: number): number[] {
    return [0, 1, -1].map((offset) => wrapIndex(current + offset, slideCount));
}
