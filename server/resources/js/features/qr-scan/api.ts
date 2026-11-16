import type { QrIdentified, QrPunched, QrRefusal } from './types';

export type Outcome<T> =
    { ok: true; data: T } | { ok: false; error: QrRefusal };

/** Laravel's XSRF cookie, so a plain fetch passes CSRF verification. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function send<T>(
    url: string,
    body: FormData | object,
): Promise<Outcome<T>> {
    let response: Response;

    try {
        response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
                ...(body instanceof FormData
                    ? {}
                    : { 'Content-Type': 'application/json' }),
            },
            body: body instanceof FormData ? body : JSON.stringify(body),
        });
    } catch {
        return {
            ok: false,
            error: {
                message:
                    'Your phone couldn’t reach SYNAPSE. Check your connection and try again.',
                reason: 'offline',
            },
        };
    }

    const data = (await response.json().catch(() => null)) as
        (T & QrRefusal) | null;

    if (response.ok && data) {
        return { ok: true, data };
    }

    if (response.status === 419) {
        return {
            ok: false,
            error: {
                message:
                    'This page was open too long. Scan the code on the screen again.',
                reason: 'expired',
            },
        };
    }

    if (response.status === 429) {
        return {
            ok: false,
            error: {
                message:
                    'Too many tries from this network. Wait a minute, then try again.',
                reason: 'throttled',
            },
        };
    }

    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;

    return {
        ok: false,
        error: {
            message: first ?? data?.message ?? 'That didn’t work. Try again.',
            reason: data?.reason,
            errors: data?.errors,
        },
    };
}

export const qrScanApi = {
    identify: (body: {
        claim: string;
        number: string;
        pin: string;
        latitude: number | null;
        longitude: number | null;
        accuracy: number | null;
    }) => send<QrIdentified>('/qr-scan/identify', body),

    choosePin: (ticket: string, pin: string) =>
        send<{ ok: true }>('/qr-scan/pin', { ticket, pin }),

    punch: (ticket: string, type: string, photo: Blob | null) => {
        const form = new FormData();
        form.append('ticket', ticket);
        form.append('type', type);

        if (photo) {
            form.append('photo', photo, 'selfie.jpg');
        }

        return send<QrPunched>('/qr-scan/punch', form);
    },
};

export type Fix = {
    latitude: number;
    longitude: number;
    accuracy: number;
};

/**
 * Where the phone is, as precisely as it can tell: a fresh fix (never a cached
 * one), with up to 15 seconds to find it.
 */
export function locate(): Promise<
    { ok: true; fix: Fix } | { ok: false; reason: 'denied' | 'unavailable' }
> {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
        return Promise.resolve({ ok: false, reason: 'unavailable' });
    }

    return new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition(
            (position) =>
                resolve({
                    ok: true,
                    fix: {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy: position.coords.accuracy,
                    },
                }),
            (error) =>
                resolve({
                    ok: false,
                    reason:
                        error.code === error.PERMISSION_DENIED
                            ? 'denied'
                            : 'unavailable',
                }),
            { enableHighAccuracy: true, timeout: 15_000, maximumAge: 0 },
        );
    });
}
