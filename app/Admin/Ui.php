<?php

namespace App\Admin;

use Illuminate\Support\Carbon;

/** Shared class strings for admin list/form pages (tokens only); mirror of components/admin/ui.ts. */
final class Ui
{
    public const CARD = 'rounded-xl border border-border bg-surface shadow-brand-sm';

    public const SELECT = 'rounded-lg border border-input-border bg-surface px-3 py-2 text-sm text-foreground shadow-brand-sm outline-none focus:border-brand-600 focus-visible:ring-2 focus-visible:ring-ring';

    /** Search box in a list's filter bar (the search icon sits at its left). */
    public const SEARCH_INPUT = 'w-full rounded-lg border border-input-border bg-surface py-2 pr-4 pl-10 text-sm text-foreground shadow-brand-sm outline-none placeholder:text-muted-foreground focus:border-brand-600 focus-visible:ring-2 focus-visible:ring-ring';

    /** Page container: lists (wide content). */
    public const WIDE_PAGE = 'mx-auto w-full max-w-7xl space-y-6 p-6';

    /** Page container: plain forms. */
    public const FORM_PAGE = 'mx-auto w-full max-w-3xl space-y-6 p-6';

    /** Page container: pages that host the block editor. No width cap, so the canvas gets the whole content area. */
    public const EDITOR_PAGE = 'w-full space-y-6 p-6';

    public const ICON_LINK = 'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors duration-150 ease-out hover:bg-accent hover:text-accent-foreground';

    public const TH = 'px-4 py-3 text-xs font-bold tracking-wide whitespace-nowrap text-muted-foreground uppercase';

    /** Status pill in a table cell: one line, never wraps. */
    public const PILL = 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap';

    public const EYEBROW = 'flex items-center gap-2 text-xs font-semibold tracking-widest text-link uppercase before:h-0.5 before:w-6 before:bg-gold-500 before:content-[""]';

    // Same strings as components/admin/shared/form-utils.ts.
    public const INPUT = 'w-full rounded-lg border border-input-border bg-surface px-3 py-2 text-sm text-foreground shadow-brand-sm outline-none placeholder:text-muted-foreground focus:border-brand-600 focus-visible:ring-2 focus-visible:ring-ring';

    public const PRIMARY_BUTTON = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white shadow-brand-sm transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-gold-500 dark:text-brand-950 dark:hover:bg-gold-400';

    public const SECONDARY_BUTTON = 'inline-flex items-center justify-center gap-2 rounded-lg border border-border-strong bg-surface px-4 py-2 text-sm font-semibold text-foreground transition-colors duration-150 ease-out hover:bg-surface-alt disabled:cursor-not-allowed disabled:opacity-60';

    public const DANGER_ICON_BUTTON = 'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors duration-150 ease-out hover:bg-destructive/10 hover:text-destructive';

    /** Date as the admin lists show it (`en-PH`, e.g. "Jan 5, 2026"; with time "Jan 5, 2026, 09:30 AM"). */
    public static function formatDate(\DateTimeInterface|string|null $value, bool $withTime = false): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $date = Carbon::parse($value)->setTimezone(config('app.timezone'));

        return $date->format($withTime ? 'M j, Y, h:i A' : 'M j, Y');
    }
}
