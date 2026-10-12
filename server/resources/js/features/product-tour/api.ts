import { productTourRoutes } from './routes';
import type { TourOutcome, TourRunMode, TourStepId } from './types';

/** Read Laravel's XSRF cookie so a plain fetch passes CSRF verification. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Tell the server the first tour is over, so it is not offered again.
 *
 * A plain fetch, not an Inertia visit: the tour closes over whatever page the
 * person is on, and that page must not reload (or lose a half-filled form)
 * because a tour finished on top of it.
 */
export async function finishTour(
    outcome: TourOutcome,
    options: { run?: number | null; answers?: boolean } = {},
): Promise<void> {
    const response = await fetch(productTourRoutes.finish, {
        method: 'POST',
        headers: {
            'X-XSRF-TOKEN': xsrfToken(),
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            outcome,
            run: options.run ?? null,
            answers: options.answers ?? true,
        }),
        keepalive: true,
    });

    // Exactly 204. A refusal on a web route is a redirect, which fetch follows
    // to a page that answers 200 — "ok", but nothing was saved.
    if (response.status !== 204) {
        throw new Error(`Saving the tour failed (${response.status}).`);
    }
}

/**
 * File a run of the tour (ADR 0080) — the stops it will show, in order — and
 * return its id, or null when it could not be filed. The tour plays either way.
 */
export async function startRun(
    mode: TourRunMode,
    steps: TourStepId[],
): Promise<number | null> {
    try {
        const response = await fetch(productTourRoutes.runs, {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': xsrfToken(),
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ mode, steps }),
        });

        if (response.status !== 201) {
            return null;
        }

        const body = (await response.json()) as { id?: number };

        return typeof body.id === 'number' ? body.id : null;
    } catch {
        return null;
    }
}

/** Tell the server a run reached a step. Fire and forget. */
export function reachStep(run: number, index: number): void {
    void fetch(productTourRoutes.run(run), {
        method: 'PATCH',
        headers: {
            'X-XSRF-TOKEN': xsrfToken(),
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ index }),
        keepalive: true,
    }).catch(() => {});
}
