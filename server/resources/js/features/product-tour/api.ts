import { productTourRoutes } from './routes';
import type { TourOutcome } from './types';

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
export async function finishTour(outcome: TourOutcome): Promise<void> {
    const response = await fetch(productTourRoutes.finish, {
        method: 'POST',
        headers: {
            'X-XSRF-TOKEN': xsrfToken(),
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ outcome }),
    });

    // Exactly 204. A refusal on a web route is a redirect, which fetch follows
    // to a page that answers 200 — "ok", but nothing was saved.
    if (response.status !== 204) {
        throw new Error(`Saving the tour failed (${response.status}).`);
    }
}
