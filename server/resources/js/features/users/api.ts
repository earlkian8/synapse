import { userRoutes } from './routes';
import type { ImportResult } from './types';

/** Read Laravel's XSRF cookie so a plain fetch passes CSRF verification. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Upload a CSV of users to the import endpoint and return its per-row result.
 * Uses a plain fetch (not Inertia) so the rich result can be shown inline in the
 * import dialog without a page navigation.
 */
export async function importUsers(file: File): Promise<ImportResult> {
    const body = new FormData();
    body.append('file', file);

    const response = await fetch(userRoutes.import, {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': xsrfToken(), Accept: 'application/json' },
        credentials: 'same-origin',
        body,
    });

    const data = (await response.json().catch(() => null)) as
        | (ImportResult & { message?: string })
        | { message?: string }
        | null;

    if (!response.ok) {
        // 422 validation (bad file) surfaces Laravel's message; anything else is generic.
        const message =
            (data && 'message' in data && data.message) ||
            'The import could not be processed.';

        throw new Error(message);
    }

    return data as ImportResult;
}
