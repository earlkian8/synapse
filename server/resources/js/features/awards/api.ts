import { router } from '@inertiajs/react';
import { awardsRoutes } from './routes';
import type { CitationResult } from './types';

type Handlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

export type GiveAwardPayload = {
    employee_id: number;
    award_type_id: number;
    awarded_on: string;
    reason?: string | null;
};

export type UpdateAwardPayload = {
    award_type_id: number;
    awarded_on: string;
    reason?: string | null;
};

const opts = (h: Handlers = {}) => ({
    preserveScroll: true,
    onStart: h.onStart,
    onFinish: h.onFinish,
    onSuccess: h.onSuccess,
    onError: h.onError,
});

/** Give a recognition to an employee. */
export function giveAward(payload: GiveAwardPayload, h: Handlers = {}): void {
    router.post(awardsRoutes.store, payload, opts(h));
}

/** Update an award (type / date / reason). */
export function updateAward(
    id: number,
    payload: UpdateAwardPayload,
    h: Handlers = {},
): void {
    router.patch(awardsRoutes.award(id), payload, opts(h));
}

/** Remove an award. */
export function removeAward(id: number, h: Handlers = {}): void {
    router.delete(awardsRoutes.award(id), opts(h));
}

/** Read Laravel's XSRF cookie so the plain fetch passes CSRF verification. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Ask the server to draft an AI citation for one employee × award type,
 * grounded in the same signals the nomination board ranked them on. Failures
 * resolve to an `unavailable` result the dialog can render — never a thrown
 * error.
 */
export async function fetchCitation(
    employeeId: number,
    awardTypeId: number,
): Promise<CitationResult> {
    let response: Response;

    try {
        response = await fetch(awardsRoutes.citation, {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': xsrfToken(),
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                employee_id: employeeId,
                award_type_id: awardTypeId,
            }),
        });
    } catch {
        return {
            available: false,
            reason: 'Couldn’t reach the server. Check your connection and try again.',
            retryable: true,
        };
    }

    const data = (await response.json().catch(() => null)) as {
        citation?: CitationResult;
    } | null;

    if (!response.ok || !data?.citation) {
        return {
            available: false,
            reason:
                response.status === 419
                    ? 'Your session expired. Refresh the page and try again.'
                    : 'Couldn’t draft a citation. Try again.',
            retryable: true,
        };
    }

    return data.citation;
}
