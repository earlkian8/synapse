import { useSyncExternalStore } from 'react';

/**
 * The portal session: the bearer token the second sign-in step returned, when it
 * ends, and how long it may sit unused (ADR 0085).
 *
 * Kept in sessionStorage, so it dies with the tab and is never shared with
 * another one, and never in a cookie the browser would send by itself. The
 * server is the judge of whether it still works; this copy exists to attach it
 * to requests and to warn before the idle limit runs out.
 */
export type Session = {
    token: string;
    expiresAt: string;
    idleMinutes: number;
    /** When a request last succeeded — the server's idle clock restarts then. */
    lastActivity: number;
};

/** Why the last session ended, for the sign-in screen to say. */
export type EndReason = 'signed_out' | 'idle' | 'expired';

const STORAGE_KEY = 'synapse.admin.session';
const REASON_KEY = 'synapse.admin.ended';
const listeners = new Set<() => void>();

const read = (): Session | null => {
    try {
        const raw = sessionStorage.getItem(STORAGE_KEY);
        const parsed = raw ? (JSON.parse(raw) as Session) : null;

        return parsed && typeof parsed.token === 'string' ? parsed : null;
    } catch {
        return null;
    }
};

let current: Session | null = read();

const write = (next: Session | null): void => {
    current = next;

    try {
        if (next) {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(next));
        } else {
            sessionStorage.removeItem(STORAGE_KEY);
        }
    } catch {
        // Blocked storage: the session lives in memory until the tab reloads.
    }

    listeners.forEach((listener) => listener());
};

export function getSession(): Session | null {
    return current;
}

export function startSession(
    token: string,
    expiresAt: string,
    idleMinutes: number,
): void {
    write({ token, expiresAt, idleMinutes, lastActivity: Date.now() });
}

/** A request went through, so the server's idle clock just restarted. */
export function touchSession(): void {
    if (current) {
        write({ ...current, lastActivity: Date.now() });
    }
}

export function endSession(reason: EndReason): void {
    if (!current) {
        return;
    }

    try {
        sessionStorage.setItem(REASON_KEY, reason);
    } catch {
        // Nothing to say on the sign-in screen, then.
    }

    write(null);
}

/** Why the previous session ended, once — the sign-in screen reads and clears it. */
export function takeEndReason(): EndReason | null {
    try {
        const reason = sessionStorage.getItem(REASON_KEY) as EndReason | null;
        sessionStorage.removeItem(REASON_KEY);

        return reason;
    } catch {
        return null;
    }
}

const subscribe = (listener: () => void) => {
    listeners.add(listener);

    return () => listeners.delete(listener);
};

export function useSession(): Session | null {
    return useSyncExternalStore(subscribe, getSession, getSession);
}
