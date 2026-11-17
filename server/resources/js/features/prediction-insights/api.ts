import type { InterpretationResult, Surface } from './types';
import { PREFIX } from './use-prediction-context';

/** Where one stored result's plain-language reading is written (ADR 0106). */
export function scoreInterpretationUrl(
    surface: Surface,
    scoreId: number,
): string {
    return `${PREFIX[surface]}/scores/${scoreId}/interpretation`;
}

/** Read Laravel's XSRF cookie so the plain fetch passes CSRF verification. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Ask the server to write (and save) a plain-language reading. Failures resolve to
 * an `unavailable` result the panel can render — never a thrown error.
 */
export async function requestInterpretation(
    url: string,
): Promise<InterpretationResult> {
    let response: Response;

    try {
        response = await fetch(url, {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': xsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        });
    } catch {
        return {
            available: false,
            reason: 'Couldn’t reach the server. Check your connection and try again.',
            retryable: true,
        };
    }

    const data = (await response.json().catch(() => null)) as {
        interpretation?: InterpretationResult;
    } | null;

    if (response.ok && data?.interpretation) {
        return data.interpretation;
    }

    return {
        available: false,
        reason:
            response.status === 429
                ? 'You’ve asked for many explanations in a short time. Try again in a minute.'
                : response.status === 419
                  ? 'Your session expired. Refresh the page and try again.'
                  : 'Couldn’t write an explanation. Try again.',
        retryable: response.status !== 419,
    };
}
