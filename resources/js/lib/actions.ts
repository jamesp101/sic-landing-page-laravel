/**
 * Client for the server actions (`POST /_actions/<module>.<action>` → App\Actions\<Module>Actions).
 * Same call shape the islands were written for: `const { data, error } = await actions.posts.create(input)`.
 * `input` is a plain object (sent as JSON) or FormData (multipart). `error` is an ActionError; a validation
 * failure is an ActionError with `fields` (see `isInputError`).
 */
import { csrfHeaders, resetCsrf } from './csrf';

export type ActionErrorCode =
    | 'BAD_REQUEST'
    | 'UNAUTHORIZED'
    | 'FORBIDDEN'
    | 'NOT_FOUND'
    | 'CONFLICT'
    | 'UNPROCESSABLE_CONTENT'
    | 'TOO_MANY_REQUESTS'
    | 'CONTENT_TOO_LARGE'
    | 'INTERNAL_SERVER_ERROR';

export class ActionError extends Error {
    readonly type: 'ActionError' | 'ActionInputError';
    readonly code: ActionErrorCode;
    readonly status: number;
    /** Field name → messages, for validation failures. */
    readonly fields?: Record<string, string[]>;

    constructor(init: {
        type?: 'ActionError' | 'ActionInputError';
        code: ActionErrorCode;
        status: number;
        message: string;
        fields?: Record<string, string[]>;
    }) {
        super(init.message);
        this.name = 'ActionError';
        this.type = init.type ?? 'ActionError';
        this.code = init.code;
        this.status = init.status;
        this.fields = init.fields;
    }
}

export function isInputError(error: unknown): error is ActionError & { fields: Record<string, string[]> } {
    return error instanceof ActionError && error.type === 'ActionInputError' && !!error.fields;
}

export type ActionResult<T = any> = { data: T; error: undefined } | { data: undefined; error: ActionError };

const STATUS_CODE: Record<number, ActionErrorCode> = {
    400: 'BAD_REQUEST',
    401: 'UNAUTHORIZED',
    403: 'FORBIDDEN',
    404: 'NOT_FOUND',
    409: 'CONFLICT',
    413: 'CONTENT_TOO_LARGE',
    419: 'FORBIDDEN',
    422: 'UNPROCESSABLE_CONTENT',
    429: 'TOO_MANY_REQUESTS',
};

export async function callAction<T = any>(name: string, input?: unknown): Promise<ActionResult<T>> {
    const headers: Record<string, string> = { Accept: 'application/json', ...(await csrfHeaders()) };
    let body: BodyInit | undefined;
    if (input instanceof FormData) {
        body = input;
    } else {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(input ?? {});
    }

    let response: Response;
    try {
        response = await fetch(`/_actions/${name}`, { method: 'POST', headers, body, credentials: 'same-origin' });
    } catch {
        return {
            data: undefined,
            error: new ActionError({
                code: 'INTERNAL_SERVER_ERROR',
                status: 0,
                message: 'Network error. Please try again.',
            }),
        };
    }

    const payload = await response.json().catch(() => null);
    if (response.status === 419) resetCsrf();
    if (response.ok) return { data: payload?.data as T, error: undefined };

    const error = payload?.error;
    return {
        data: undefined,
        error: new ActionError({
            type: error?.type,
            code: error?.code ?? STATUS_CODE[response.status] ?? 'INTERNAL_SERVER_ERROR',
            status: response.status,
            message:
                error?.message ??
                (response.status === 419
                    ? 'Your session expired. Reload the page and try again.'
                    : 'Something went wrong. Please try again.'),
            fields: error?.fields,
        }),
    };
}

type ActionFn = (input?: any) => Promise<ActionResult>;

/** `actions.posts.create(input)` → `callAction('posts.create', input)`. */
export const actions: Record<string, Record<string, ActionFn>> = new Proxy(
    {},
    {
        get: (_, module: string) =>
            new Proxy(
                {},
                { get: (_, action: string) => (input?: unknown) => callAction(`${module}.${action}`, input) },
            ),
    },
);
