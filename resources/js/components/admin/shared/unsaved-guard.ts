/**
 * Unsaved-changes guard, driven by a dirty flag (`isDirty`). While dirty it holds back what would leave the page
 * and hands it to `onHold` to ask the user; `proceed` then performs the held navigation.
 *
 * Navigation is a full page load. Plain link clicks and form submits are held by capture-phase listeners, so the
 * in-app dialog can ask first; everything else that leaves the page (back/forward, address bar, closing the tab)
 * is covered by `beforeunload`, which makes the browser ask.
 *
 * Free of Svelte; `components/admin/shared/UnsavedChangesGuard.svelte` wires it up.
 */
import { plainLinkAt } from '@/lib/link-intent';

/** Forms (or any ancestors) marked with this attribute belong to the guarded page: their own submit is never held. */
export const OWN_FORM_SELECTOR = '[data-unsaved-own]';

/** A navigation or form submit held back until the user decides. */
export type Held = { href: string } | { form: HTMLFormElement; submitter: HTMLElement | null };

export interface UnsavedGuardOptions {
    isDirty: () => boolean;
    onHold: (held: Held) => void;
    /** Full page navigation (`navigate` from `@/lib/navigation`). */
    navigate: (href: string) => Promise<unknown>;
    /** Plain link under an event target, or null; replaceable in tests. */
    linkAt?: (target: EventTarget | null) => URL | null;
}

/** A primary-button click without modifiers: the kind that navigates in the same tab. */
export function isPlainPrimaryClick(
    event: Pick<MouseEvent, 'button' | 'metaKey' | 'ctrlKey' | 'shiftKey' | 'altKey'>,
): boolean {
    return event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
}

export function createUnsavedGuard(options: UnsavedGuardOptions) {
    const linkAt = options.linkAt ?? plainLinkAt;
    /** Set while a confirmed discard is leaving, so the guard lets that navigation through. */
    let discarding = false;
    /** The held form being re-submitted, until its submit event arrives (a form the browser refuses to submit never fires one). */
    let resubmitting: HTMLFormElement | null = null;
    const holding = () => options.isDirty() && !discarding;

    function click(event: MouseEvent): void {
        if (!holding() || !isPlainPrimaryClick(event)) return;
        const url = linkAt(event.target);
        if (!url) return;
        event.preventDefault();
        event.stopPropagation();
        options.onHold({ href: url.href });
    }

    function submit(event: SubmitEvent): void {
        const form = event.target as HTMLFormElement;
        if (resubmitting === form) resubmitting = null;
        if (!holding() || form.closest(OWN_FORM_SELECTOR)) return;
        event.preventDefault();
        event.stopPropagation();
        options.onHold({ form, submitter: event.submitter });
    }

    function beforeUnload(event: BeforeUnloadEvent): void {
        if (holding()) event.preventDefault();
    }

    /** The page is shown again (e.g. restored from the back/forward cache): a leave that was under way is over. */
    function settled(): void {
        discarding = false;
        resubmitting = null;
    }

    /** Leaves for good: the held link is followed, or the held form is submitted as it was. */
    async function proceed(held: Held): Promise<void> {
        discarding = true;
        if ('href' in held) {
            // The page unloads; this promise does not settle, so the guard stays open until then.
            await options.navigate(held.href);
        } else {
            // The browser submits the form and replaces the page, so the guard stays open. A form it refuses to
            // submit (it is invalid) fires no submit event: nothing is leaving, so the guard closes again at once.
            resubmitting = held.form;
            held.form.requestSubmit(held.submitter);
            if (resubmitting) settled();
        }
    }

    /** Starts listening; returns the function that stops. */
    function install(): () => void {
        window.addEventListener('beforeunload', beforeUnload);
        window.addEventListener('pageshow', settled);
        document.addEventListener('click', click, true);
        document.addEventListener('submit', submit, true);
        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            window.removeEventListener('pageshow', settled);
            document.removeEventListener('click', click, true);
            document.removeEventListener('submit', submit, true);
        };
    }

    return { click, submit, beforeUnload, settled, proceed, install };
}
