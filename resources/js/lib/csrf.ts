/**
 * CSRF token for requests to the app. Admin, settings and sign-in layouts emit `<meta name="csrf-token">`.
 * Public pages are cached for every visitor and carry no token: there it is fetched once from `/csrf-token`
 * (no-store), which also starts the visitor's session.
 */
export function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

let fetched: Promise<string> | null = null;

async function fetchToken(): Promise<string> {
    const response = await fetch('/csrf-token', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    if (!response.ok) return '';
    const body = (await response.json().catch(() => null)) as { token?: string } | null;
    return body?.token ?? '';
}

/** Header to send with every non-GET request (`fetch` and XHR alike). */
export async function csrfHeaders(): Promise<Record<string, string>> {
    const token = csrfToken() || (await (fetched ??= fetchToken()));
    return token ? { 'X-CSRF-TOKEN': token } : {};
}

/** Forget a fetched token after the server rejected it (419), so the next call fetches a fresh one. */
export function resetCsrf(): void {
    fetched = null;
}
