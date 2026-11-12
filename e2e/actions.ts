import type { APIRequestContext, APIResponse, Page } from '@playwright/test';

/** A file part of a multipart action input. */
export interface FilePart {
    name: string;
    mimeType: string;
    buffer: Buffer;
}

/** An action's input: a JSON body or multipart fields (strings, numbers, booleans and files). */
export type ActionInput = { json: unknown } | { multipart: Record<string, string | number | boolean | FilePart> };

export interface ActionResult<T = unknown> {
    ok: boolean;
    status: number;
    /** The decoded result: `data` of a successful action, or the error object `{ type, code, message, fields? }`. */
    data: T;
    /** The raw response body. */
    text: string;
}

function decode(text: string): unknown {
    if (!text) return undefined;
    let parsed: unknown;
    try {
        parsed = JSON.parse(text);
    } catch {
        return undefined;
    }
    if (parsed === null || typeof parsed !== 'object') return parsed;
    const envelope = parsed as { data?: unknown; error?: unknown };
    if ('error' in envelope) return envelope.error;
    if ('data' in envelope) return envelope.data;
    return parsed;
}

/** The CSRF token of `request`'s session: `GET /csrf-token` (it also starts the session of a cookie-less context). */
async function fetchCsrfToken(request: APIRequestContext): Promise<string> {
    const response = await request.get('/csrf-token', { headers: { Accept: 'application/json' } });
    return ((await response.json()) as { token: string }).token;
}

/** The page's CSRF token: its `<meta name="csrf-token">`, else `GET /csrf-token` (public pages carry none). */
async function csrfToken(page: Page): Promise<string> {
    const meta = await page.evaluate(() => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content);
    return meta || fetchCsrfToken(page.request);
}

const TOKENS = new WeakMap<APIRequestContext, Promise<string>>();

/** `X-CSRF-TOKEN` header for a direct request context (upload endpoints, anonymous calls), fetched once per context. */
export async function csrfHeaders(request: APIRequestContext): Promise<Record<string, string>> {
    let token = TOKENS.get(request);
    if (!token) TOKENS.set(request, (token = fetchCsrfToken(request)));
    return { 'X-CSRF-TOKEN': await token };
}

type SendOptions = NonNullable<Parameters<APIRequestContext['post']>[1]>;

/** `request.post` with the context's CSRF token (every non-GET request of the app needs one). */
export async function apiPost(
    request: APIRequestContext,
    url: string,
    options: SendOptions = {},
): Promise<APIResponse> {
    return request.post(url, { ...options, headers: { ...(await csrfHeaders(request)), ...options.headers } });
}

/** `request.put` with the context's CSRF token. */
export async function apiPut(request: APIRequestContext, url: string, options: SendOptions = {}): Promise<APIResponse> {
    return request.put(url, { ...options, headers: { ...(await csrfHeaders(request)), ...options.headers } });
}

/**
 * Posts the named action (`posts.create`, `carousels.update`, …) to `/_actions/<name>` with `page`'s session and
 * CSRF token. The page must have loaded a URL of the site; `T` is the result shape the caller expects.
 */
export async function postAction<T = unknown>(
    page: Page,
    name: string,
    input: ActionInput = { json: {} },
): Promise<ActionResult<T>> {
    const response = await page.request.post(`/_actions/${name}`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': await csrfToken(page) },
        ...('json' in input ? { data: input.json } : { multipart: input.multipart }),
    });
    const text = await response.text();
    return { ok: response.ok(), status: response.status(), data: decode(text) as T, text };
}
