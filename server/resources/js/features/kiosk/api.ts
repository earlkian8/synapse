/**
 * The kiosk's side of the device API (ADR 0040). The tablet holds its device's
 * key in this browser, and every call sends it as `X-Device-Key` — no user is
 * signed in on a shared tablet.
 */

const STORAGE_KEY = 'synapse.kiosk.key';

export type KioskDevice = {
    name: string;
    type: 'kiosk' | 'biometric';
    location: string | null;
    organization: string | null;
    timezone: string;
};

export type KioskPerson = {
    first_name: string;
    full_name: string;
    initials: string;
    next_expected: KioskPunchType | null;
    allowed: KioskPunchType[];
    first_in_at: string | null;
    last_out_at: string | null;
};

export type KioskPunchType =
    'clock_in' | 'break_start' | 'break_end' | 'clock_out';

export type KioskOutcome<T> =
    { ok: true; data: T } | { ok: false; status: number; message: string };

/**
 * The key this tablet was set up with. The setup link carries it in the URL's
 * fragment (`#key=…`), which never reaches a server log; it is moved into
 * storage here, and {@link clearKeyFromAddress} wipes it from the address bar.
 */
export function readKey(): string | null {
    const fragment = new URLSearchParams(window.location.hash.slice(1)).get(
        'key',
    );

    try {
        if (fragment) {
            window.localStorage.setItem(STORAGE_KEY, fragment);
        }

        return window.localStorage.getItem(STORAGE_KEY);
    } catch {
        // Storage refused (a private window): the key lives for this page only.
        return fragment;
    }
}

/**
 * Take the key out of the address bar — so it is not left on screen, in a
 * bookmark, or in the tablet's history — once it is safely stored. Inertia keeps
 * the fragment in its own idea of the page's address and writes it back on
 * every history update, so the page is loaded again without it rather than
 * patched. When storage was refused the fragment is the only copy of the key,
 * so it stays.
 */
export function clearKeyFromAddress(): void {
    if (!window.location.hash.includes('key=')) {
        return;
    }

    try {
        if (window.localStorage.getItem(STORAGE_KEY)) {
            window.location.replace(window.location.pathname);
        }
    } catch {
        // No storage: keep the key where it is.
    }
}

export function forgetKey(): void {
    try {
        window.localStorage.removeItem(STORAGE_KEY);
    } catch {
        // Nothing stored to forget.
    }
}

async function call<T>(
    key: string,
    method: 'GET' | 'POST',
    path: string,
    body?: FormData | Record<string, unknown>,
): Promise<KioskOutcome<T>> {
    const isForm = body instanceof FormData;

    try {
        const response = await fetch(`/api/devices/${path}`, {
            method,
            headers: {
                Accept: 'application/json',
                'X-Device-Key': key,
                ...(body && !isForm
                    ? { 'Content-Type': 'application/json' }
                    : {}),
            },
            body:
                body === undefined
                    ? undefined
                    : isForm
                      ? body
                      : JSON.stringify(body),
        });

        const json = (await response.json().catch(() => null)) as {
            data?: T;
            message?: string;
        } | null;

        if (response.ok && json?.data !== undefined) {
            return { ok: true, data: json.data };
        }

        return {
            ok: false,
            status: response.status,
            message:
                json?.message ??
                (response.status === 429
                    ? 'Too many punches at once. Wait a moment and try again.'
                    : 'Something went wrong. Try again.'),
        };
    } catch {
        return {
            ok: false,
            status: 0,
            message: 'The kiosk can’t reach the server. Check its connection.',
        };
    }
}

export const kioskApi = {
    me: (key: string) => call<KioskDevice>(key, 'GET', 'me'),
    lookup: (key: string, reference: string) =>
        call<KioskPerson>(key, 'POST', 'kiosk/lookup', {
            employee_ref: reference,
        }),
    punch: (
        key: string,
        reference: string,
        type: KioskPunchType,
        photo: Blob | null,
    ) => {
        const form = new FormData();
        form.append('employee_ref', reference);
        form.append('type', type);

        if (photo) {
            form.append('photo', photo, 'kiosk.jpg');
        }

        return call<{ type: KioskPunchType; at: string; status: string }>(
            key,
            'POST',
            'kiosk/punch',
            form,
        );
    },
};
