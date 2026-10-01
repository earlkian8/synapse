import type { Insights } from './types';

/** Read Laravel's XSRF cookie so the plain fetch passes CSRF verification. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** Build the query string for the active filters (skipping defaults). */
function query(applied: Record<string, string>): string {
    const params = new URLSearchParams();

    for (const [name, value] of Object.entries(applied)) {
        if (value !== '' && value !== 'all') {
            params.set(name, value);
        }
    }

    const string = params.toString();

    return string ? `?${string}` : '';
}

/**
 * Ask the server to generate LLM decision-support for the current report run. The
 * server re-runs the report from these filters, so the insight always reflects the
 * exact figures on screen. Failures resolve to an `unavailable` result the panel can
 * render — never a thrown error.
 */
export async function fetchInsights(
    key: string,
    applied: Record<string, string>,
): Promise<Insights> {
    let response: Response;

    try {
        response = await fetch(`/reports/${key}/insights${query(applied)}`, {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': xsrfToken(),
                Accept: 'application/json',
            },
        });
    } catch {
        return {
            available: false,
            reason: 'Couldn’t reach the server. Check your connection and try again.',
            retryable: true,
        };
    }

    const data = (await response.json().catch(() => null)) as {
        insights?: Insights;
    } | null;

    if (!response.ok || !data?.insights) {
        return {
            available: false,
            reason:
                response.status === 419
                    ? 'Your session expired. Refresh the page and try again.'
                    : 'Couldn’t generate insights. Try again.',
            retryable: true,
        };
    }

    return data.insights;
}
