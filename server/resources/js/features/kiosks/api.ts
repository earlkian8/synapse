import { kioskAdminRoutes } from './routes';
import type { IssuedPin, PairingCode } from './types';

/**
 * The two secrets this screen hands out — a pairing code, and PINs — come back
 * as JSON to show once (ADR 0092). They are never page props or flash data,
 * which the browser would keep in its history.
 */

export type Outcome<T> = { ok: true; data: T } | { ok: false; message: string };

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function post<T>(url: string, body: object): Promise<Outcome<T>> {
    let response: Response;

    try {
        response = await fetch(url, {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': xsrfToken(),
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });
    } catch {
        return { ok: false, message: 'Couldn’t reach the server. Try again.' };
    }

    const data = (await response.json().catch(() => null)) as
        (T & { message?: string; errors?: Record<string, string[]> }) | null;

    if (response.ok && data !== null) {
        return { ok: true, data };
    }

    const firstError = data?.errors
        ? Object.values(data.errors).flat()[0]
        : undefined;

    return {
        ok: false,
        message:
            response.status === 419
                ? 'Your session expired. Refresh the page and try again.'
                : (firstError ??
                  data?.message ??
                  'That didn’t work. Try again.'),
    };
}

export function requestPairingCode(hashid: string) {
    return post<PairingCode>(kioskAdminRoutes.pairingCode(hashid), {});
}

export function issuePins(employeeIds: number[]) {
    return post<{ issued: IssuedPin[] }>(kioskAdminRoutes.issuePins, {
        employee_ids: employeeIds,
    });
}
