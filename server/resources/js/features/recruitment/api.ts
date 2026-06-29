import { recruitmentRoutes } from './routes';
import type { ApplicantInsightResult } from './types';

/** Read Laravel's XSRF cookie so the plain fetch passes CSRF verification. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Ask the server to generate (and persist) LLM decision-support for one
 * application — the model reads the candidate's résumé and supporting documents.
 * Failures resolve to an `unavailable` result the panel can render — never a
 * thrown error.
 */
export async function fetchApplicantInsights(
    applicationId: number,
): Promise<ApplicantInsightResult> {
    let response: Response;

    try {
        response = await fetch(
            recruitmentRoutes.applicationInsights(applicationId),
            {
                method: 'POST',
                headers: {
                    'X-XSRF-TOKEN': xsrfToken(),
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            },
        );
    } catch {
        return {
            available: false,
            reason: 'Couldn’t reach the server. Check your connection and try again.',
            retryable: true,
        };
    }

    const data = (await response.json().catch(() => null)) as {
        insights?: ApplicantInsightResult;
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
