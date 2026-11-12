/**
 * Hover prefetch for the public site: pointing at, focusing or touching a link to a public page loads that
 * page in the background, so the click shows it from the HTTP cache. Anonymous public pages are sent with
 * a public `Cache-Control` (max-age=60), which is what lets the prefetched copy be reused; signed-in visitors get
 * `no-store`, so `PublicLayout` only loads this script for anonymous visitors, and it also stays quiet when the
 * browser carries the `sic_staff` hint (a signed-in person served cached anonymous HTML). Each URL is
 * prefetched once (`<link rel="prefetch">`); visitors on Save-Data or slow connections are left alone.
 */
import { plainLinkAt } from '@/lib/link-intent';
import { isPublicPage } from '@/lib/public-page';
import { hasStaffHint } from '@/lib/staff-hint';

const prefetched = new Set<string>();

function saveData(): boolean {
    const connection = (navigator as Navigator & { connection?: { saveData?: boolean; effectiveType?: string } })
        .connection;
    return !!connection && (!!connection.saveData || /(2g)$/.test(connection.effectiveType ?? ''));
}

function prefetch(href: string): void {
    if (prefetched.has(href) || saveData()) return;
    prefetched.add(href);
    const link = document.createElement('link');
    link.rel = 'prefetch';
    link.href = href;
    link.as = 'document';
    document.head.append(link);
}

/** A pointer passing over a link on its way elsewhere, or a finger starting a scroll, should not fetch it. */
const HOVER_DELAY_MS = 65;

function publicPageUrl(target: EventTarget | null): string | null {
    const url = plainLinkAt(target);
    return url && isPublicPage(url.pathname) ? url.href : null;
}

if (!hasStaffHint(document.cookie)) {
    let pending: { anchor: Element; timer: number } | null = null;

    const cancel = () => {
        if (pending) clearTimeout(pending.timer);
        pending = null;
    };
    /** Prefetch after the hover delay; moving on to another link restarts it, staying on the same one keeps it. */
    const schedule = (event: Event) => {
        const href = publicPageUrl(event.target);
        const anchor = href && (event.target as Element).closest('a');
        if (!href || !anchor || pending?.anchor === anchor) return;
        cancel();
        pending = { anchor, timer: window.setTimeout(() => prefetch(href), HOVER_DELAY_MS) };
    };

    document.addEventListener('mouseover', schedule, { passive: true });
    document.addEventListener(
        'mouseout',
        (event) => {
            if (pending && !pending.anchor.contains(event.relatedTarget as Node | null)) cancel();
        },
        { passive: true },
    );

    // Touch waits like hover; a finger that starts moving is a scroll, not a tap.
    document.addEventListener('touchstart', schedule, { passive: true });
    document.addEventListener('touchmove', cancel, { passive: true });

    // Keyboard focus shows intent immediately.
    document.addEventListener(
        'focusin',
        (event) => {
            const href = publicPageUrl(event.target);
            if (href) prefetch(href);
        },
        { passive: true },
    );
}
