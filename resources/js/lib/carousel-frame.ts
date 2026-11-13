/**
 * Slide frames: the breakpoint, aspect ratios and hero heights a Slide is drawn in, for the public Carousel and the
 * designer alike. No imports, so the public island can use it without pulling in Overlay rules.
 *
 * Facts CSS must repeat (media/container queries cannot read TypeScript) live in `resources/css/slide-layout.css`;
 * `carousel-frame.test.ts` fails when that file disagrees with the constants below or when any other source file
 * hard-codes them.
 */

/** Phones are everything narrower than Tailwind's `sm` (40rem), measured on the hero (container) or the viewport (media). */
export const PHONE_MAX_REM = 39.99;
/** Below this width the hero is shorter; from it up the hero takes its wide height. */
export const WIDE_MIN_REM = 48;

/** The phone frame is 4:5 (width : height). */
export const PHONE_FRAME = { w: 4, h: 5 } as const;
/** The desktop stage is 16:9; layer percentages are of this box. */
export const DESKTOP_FRAME = { w: 16, h: 9 } as const;

/** Tailwind aspect classes of the designer stage per layout (literal strings so Tailwind sees them). */
export const FRAME_ASPECT_CLASS = { desktop: 'aspect-video', phone: 'aspect-[4/5]' } as const;

/** Hero heights in px: `tall` is the home page, `short` a Department page; `base` below `WIDE_MIN_REM`, `wide` from it. */
export const HERO_HEIGHT = {
    tall: { base: 500, wide: 600 },
    short: { base: 400, wide: 450 },
} as const;

/**
 * Width (px) the hero photo is drawn at on a phone: `object-cover` fits the 16:9 image to the hero's height, so it
 * is wider than the screen. Keyed like `HERO_HEIGHT`.
 */
export const HERO_PHONE_IMAGE_WIDTH = {
    tall: Math.ceil((HERO_HEIGHT.tall.base * DESKTOP_FRAME.w) / DESKTOP_FRAME.h),
    short: Math.ceil((HERO_HEIGHT.short.base * DESKTOP_FRAME.w) / DESKTOP_FRAME.h),
} as const;

/** `sizes` for a hero photo: the phone width below the phone breakpoint, the viewport otherwise. */
export const heroImageSizes = (tall: boolean): string =>
    `(max-width: ${PHONE_MAX_REM}rem) ${HERO_PHONE_IMAGE_WIDTH[tall ? 'tall' : 'short']}px, 100vw`;

/** Screen width (px) the designer's "visible area" guide is drawn for. */
export const GUIDE_SCREEN_WIDTH = 1440;

/** Share (percent) of the 16:9 slide height still visible on a `GUIDE_SCREEN_WIDTH` wide screen on the home page. */
export const VISIBLE_BAND = (HERO_HEIGHT.tall.wide / ((GUIDE_SCREEN_WIDTH * DESKTOP_FRAME.h) / DESKTOP_FRAME.w)) * 100;
/** Percent of the slide height cropped away at the top and again at the bottom. */
export const CROP = (100 - VISIBLE_BAND) / 2;
