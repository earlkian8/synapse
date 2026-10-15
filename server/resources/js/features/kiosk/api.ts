import { kioskRoutes } from './routes';
import type {
    KioskInfo,
    KioskPunchResult,
    KioskPunchType,
    KioskRefusal,
    KioskSession,
} from './types';

/**
 * The kiosk's calls to its server (ADR 0092). Nobody is signed in: the device is
 * known by the HttpOnly cookie its pairing set, which the browser sends and no
 * script here can read. Posts carry the form token from Laravel's XSRF cookie.
 *
 * Every call resolves to an outcome rather than throwing, so a screen can say
 * what went wrong beside the keypad.
 */

export type KioskOutcome<T> =
    | { ok: true; data: T }
    | {
          ok: false;
          status: number;
          message: string;
          reason: KioskRefusal | null;
          errors: Record<string, string[]>;
      };

const OFFLINE =
    'This kiosk can’t reach the server. Check its connection and try again.';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function call<T>(
    method: 'GET' | 'POST',
    url: string,
    body?: Record<string, unknown> | FormData,
    retried = false,
): Promise<KioskOutcome<T>> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    if (method === 'POST') {
        headers['X-XSRF-TOKEN'] = xsrfToken();
    }

    if (body !== undefined && !(body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
    }

    let response: Response;

    try {
        response = await fetch(url, {
            method,
            headers,
            credentials: 'same-origin',
            body:
                body === undefined
                    ? undefined
                    : body instanceof FormData
                      ? body
                      : JSON.stringify(body),
        });
    } catch {
        return {
            ok: false,
            status: 0,
            message: OFFLINE,
            reason: null,
            errors: {},
        };
    }

    // The form token outlived its session (a kiosk left overnight). Asking for
    // the kiosk's state starts a fresh one; then the post goes once more.
    if (response.status === 419 && !retried) {
        await fetch(kioskRoutes.state, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        }).catch(() => null);

        return call<T>(method, url, body, true);
    }

    if (response.status === 204) {
        return { ok: true, data: undefined as T };
    }

    const data = (await response.json().catch(() => null)) as
        | (T & {
              message?: string;
              reason?: KioskRefusal;
              errors?: Record<string, string[]>;
          })
        | null;

    if (response.ok && data !== null) {
        return { ok: true, data };
    }

    return {
        ok: false,
        status: response.status,
        message:
            data?.message ??
            (response.status === 429
                ? 'Too many tries at once. Wait a moment and try again.'
                : 'Something went wrong. Try again.'),
        reason: data?.reason ?? null,
        errors: data?.errors ?? {},
    };
}

export function pair(code: string) {
    return call<{ kiosk: KioskInfo }>('POST', kioskRoutes.pair, { code });
}

export function fetchState() {
    return call<{ kiosk: KioskInfo }>('GET', kioskRoutes.state);
}

export function signIn(number: string, pin: string) {
    return call<KioskSession>('POST', kioskRoutes.signIn, { number, pin });
}

export function choosePin(ticket: string, pin: string, confirmation: string) {
    return call<KioskSession>('POST', kioskRoutes.pin, {
        ticket,
        pin,
        pin_confirmation: confirmation,
    });
}

export function punch(
    ticket: string,
    type: KioskPunchType,
    photo: Blob | null,
) {
    const form = new FormData();
    form.append('ticket', ticket);
    form.append('type', type);

    if (photo) {
        form.append('photo', photo, 'kiosk.jpg');
    }

    return call<KioskPunchResult>('POST', kioskRoutes.punch, form);
}

/** Drop a ticket nobody will use — fire and forget. */
export function done(ticket: string): void {
    void call<void>('POST', kioskRoutes.done, { ticket });
}
