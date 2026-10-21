import { API_ROOT } from './env';
import { endSession, getSession, touchSession } from './session';

/**
 * A failed call, with what the portal needs to show it: the field errors of a
 * 422, and the server's `reason` when it gives one.
 */
export class ApiError extends Error {
    readonly status: number;
    readonly errors: Record<string, string[]>;
    readonly reason?: string;

    constructor(
        status: number,
        message: string,
        errors: Record<string, string[]> = {},
        reason?: string,
    ) {
        super(message);
        this.status = status;
        this.errors = errors;
        this.reason = reason;
    }

    /** The first message for a field, for under an input. */
    field(name: string): string | undefined {
        return this.errors[name]?.[0];
    }
}

type Options = {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    body?: unknown;
    signal?: AbortSignal;
};

const listeners = new Set<() => void>();

/** Called when the server says the admin must choose a new password first. */
export function onPasswordChangeRequired(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

/**
 * Call the portal API. Sends the session's bearer token — never cookies — and
 * asks every cache to keep nothing. A 401 on a signed-in call means the session
 * is over (idle, expired, revoked or deactivated), so it ends here, once, for
 * every screen.
 */
export async function api<T>(
    path: string,
    { method = 'GET', body, signal }: Options = {},
): Promise<T> {
    const session = getSession();
    const headers: Record<string, string> = { Accept: 'application/json' };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    if (session) {
        headers.Authorization = `Bearer ${session.token}`;
    }

    let response: Response;

    try {
        response = await fetch(`${API_ROOT}${path}`, {
            method,
            headers,
            body: body === undefined ? undefined : JSON.stringify(body),
            signal,
            credentials: 'omit',
            cache: 'no-store',
            referrerPolicy: 'no-referrer',
        });
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw error;
        }

        throw new ApiError(
            0,
            "The ERP can't be reached. Check your connection, then try again.",
        );
    }

    const data = (await response.json().catch(() => null)) as Record<
        string,
        unknown
    > | null;

    if (response.ok) {
        if (session) {
            touchSession();
        }

        return data as T;
    }

    if (response.status === 401 && session) {
        endSession('expired');
    }

    if (
        response.status === 403 &&
        data?.reason === 'password_change_required'
    ) {
        listeners.forEach((listener) => listener());
    }

    throw new ApiError(
        response.status,
        messageFor(response.status, data),
        (data?.errors as Record<string, string[]> | undefined) ?? {},
        typeof data?.reason === 'string' ? data.reason : undefined,
    );
}

function messageFor(
    status: number,
    data: Record<string, unknown> | null,
): string {
    if (status === 429) {
        return 'Too many attempts. Wait a minute, then try again.';
    }

    const errors = data?.errors as Record<string, string[]> | undefined;
    const first = errors ? Object.values(errors)[0]?.[0] : undefined;

    if (first) {
        return first;
    }

    if (typeof data?.message === 'string' && data.message !== '') {
        return data.message;
    }

    if (status === 404) {
        return "That record doesn't exist, or it was removed.";
    }

    return 'Something went wrong on the server. Try again in a moment.';
}

/**
 * A file the portal shows but may not link to — a screenshot on a problem
 * report — fetched with the session's token, as a blob for an object URL.
 */
export async function apiBlob(
    path: string,
    signal?: AbortSignal,
): Promise<Blob> {
    const session = getSession();

    const response = await fetch(`${API_ROOT}${path}`, {
        headers: session ? { Authorization: `Bearer ${session.token}` } : {},
        signal,
        credentials: 'omit',
        cache: 'no-store',
        referrerPolicy: 'no-referrer',
    });

    if (response.status === 401 && session) {
        endSession('expired');
    }

    if (!response.ok) {
        throw new ApiError(response.status, messageFor(response.status, null));
    }

    touchSession();

    return response.blob();
}

/** A query string from a filter object, leaving out what is empty. */
export function query(
    params: Record<string, string | number | null | undefined>,
): string {
    const search = new URLSearchParams();

    Object.entries(params).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            search.set(key, String(value));
        }
    });

    const text = search.toString();

    return text ? `?${text}` : '';
}
