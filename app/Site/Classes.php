<?php

namespace App\Site;

/** Shared Tailwind class strings for public components (tokens only, no hex colours); mirrors `resources/js/components/site/classes.ts`. */
final class Classes
{
    private const BUTTON = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border py-3 text-xs font-semibold uppercase tracking-widest transition duration-200 ease-out motion-safe:hover:-translate-y-0.5 disabled:pointer-events-none disabled:opacity-60';

    private const GOLD = 'border-transparent bg-gold-500 text-brand-950 shadow-brand-sm hover:bg-gold-400 hover:shadow-brand-md';

    private const PRIMARY = 'border-transparent bg-primary text-primary-foreground shadow-brand-sm hover:opacity-90 hover:shadow-brand-md';

    /** Gold call-to-action, navy text. */
    public const BTN_GOLD = self::BUTTON.' px-6 '.self::GOLD;

    /** Primary action on light/dark surfaces (navy in light mode, gold in dark mode). */
    public const BTN_PRIMARY = self::BUTTON.' px-6 '.self::PRIMARY;

    /** Primary action inside a card or a form row: same look, tighter sides. */
    public const BTN_PRIMARY_COMPACT = self::BUTTON.' px-4 '.self::PRIMARY;

    /** Neutral outlined button on page surfaces. */
    public const BTN_OUTLINE = self::BUTTON.' px-6 border-border-strong bg-surface text-foreground hover:bg-surface-alt';

    /** Compact outlined button for secondary actions inside a card. */
    public const BTN_OUTLINE_COMPACT = self::BUTTON.' px-4 border-border-strong bg-surface text-link hover:bg-surface-alt';

    /** Outlined button on navy backgrounds. */
    public const BTN_ON_DARK = self::BUTTON.' px-6 border-white/30 text-white hover:bg-white/10';

    public const FIELD = 'block min-h-11 w-full rounded-xl border border-input-border bg-surface px-4 py-2.5 text-sm text-foreground placeholder:text-muted-foreground';

    public const CARD = 'rounded-2xl border border-border bg-surface shadow-brand-sm transition duration-200 ease-out hover:shadow-brand-md motion-safe:hover:-translate-y-1 dark:border-border-strong';

    /** Text link that stays a 44 px tap target on phones. */
    public const TEXT_LINK = 'inline-flex min-h-11 items-center rounded text-sm font-semibold text-link underline-offset-4 hover:underline md:min-h-8';
}
