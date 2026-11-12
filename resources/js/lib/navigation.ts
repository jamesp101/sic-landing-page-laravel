/**
 * Admin navigation: full page loads (no client router). Islands use these instead of `window.location`
 * so the unsaved-changes guard and tests have one seam.
 */
export function navigate(href: string, options: { history?: 'push' | 'replace' } = {}): Promise<void> {
    if (options.history === 'replace') window.location.replace(href);
    else window.location.assign(href);
    // The page unloads; callers awaiting this keep their "processing" state until then.
    return new Promise(() => {});
}

/** Reloads the current view (fresh server data and any queued flash toast). */
export function reloadView(): Promise<void> {
    window.location.reload();
    return new Promise(() => {});
}
