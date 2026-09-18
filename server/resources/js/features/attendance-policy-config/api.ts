import { attendancePolicyRoutes } from './routes';
import type { DayVerdict, PolicySettings, SampleDay } from './types';

export type PreviewOutcome =
    { ok: true; result: DayVerdict } | { ok: false; message: string };

/** Read Laravel's XSRF cookie so the plain fetch passes CSRF verification. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Ask the server to judge the sample day by the settings on screen — the same
 * evaluator the board uses, so the example cannot drift from the real rules.
 * Resolves to a message rather than throwing, so the example can say what is
 * wrong beside itself.
 */
export async function previewDay(
    settings: PolicySettings,
    sample: SampleDay,
    signal?: AbortSignal,
): Promise<PreviewOutcome> {
    let response: Response;

    try {
        response = await fetch(attendancePolicyRoutes.preview, {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': xsrfToken(),
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            signal,
            body: JSON.stringify({
                settings,
                sample: {
                    ...sample,
                    time_out: sample.time_out || null,
                    break_start: sample.break_start || null,
                    break_end: sample.break_end || null,
                },
            }),
        });
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw error;
        }

        return {
            ok: false,
            message: 'Couldn’t reach the server to work the example out.',
        };
    }

    const data = (await response.json().catch(() => null)) as {
        result?: DayVerdict;
        message?: string;
    } | null;

    if (response.ok && data?.result) {
        return { ok: true, result: data.result };
    }

    return {
        ok: false,
        message:
            response.status === 422 && data?.message
                ? data.message
                : response.status === 419
                  ? 'Your session expired. Refresh the page to see the example.'
                  : 'The example could not be worked out.',
    };
}
