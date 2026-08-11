import { router } from '@inertiajs/react';
import { performanceRoutes } from './routes';
import type { PerformanceInsightResult } from './types';

/** Read Laravel's XSRF cookie so the plain fetch passes CSRF verification. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Ask the server to generate (and persist) the LLM performance read for one
 * evaluation. Failures resolve to an `unavailable` result the panel can render —
 * never a thrown error.
 */
export async function fetchPerformanceInsights(
    hashid: string,
): Promise<PerformanceInsightResult> {
    let response: Response;

    try {
        response = await fetch(performanceRoutes.insights(hashid), {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': xsrfToken(),
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
        insights?: PerformanceInsightResult;
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

type Handlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

export type CreateEvaluationPayload = {
    employee_id: number;
    evaluation_period_id: number;
    review_template_id: number | null;
};

export type LaunchCyclePayload = {
    evaluation_period_id: number;
    review_template_id: number | null;
    scope: 'all' | 'departments';
    department_ids: number[];
};

export type ScoreLinePayload = {
    id: number;
    score: number | null;
    remarks: string | null;
};

export type SaveEvaluationPayload = {
    remarks: string | null;
    scores: ScoreLinePayload[];
};

const opts = (h: Handlers = {}) => ({
    preserveScroll: true,
    onStart: h.onStart,
    onFinish: h.onFinish,
    onSuccess: h.onSuccess,
    onError: h.onError,
});

/** Open a new appraisal; the server redirects to its scorecard. */
export function createEvaluation(
    payload: CreateEvaluationPayload,
    h: Handlers = {},
): void {
    router.post(performanceRoutes.store, payload, opts(h));
}

/**
 * Open every appraisal of a cycle at once. Idempotent server-side — anyone
 * already appraised in the cycle is skipped, so it is safe to re-run.
 */
export function launchCycle(
    payload: LaunchCyclePayload,
    h: Handlers = {},
): void {
    router.post(performanceRoutes.launchCycle, payload, opts(h));
}

/** Save the scorecard (ratings + remarks) of a draft evaluation. */
export function saveEvaluation(
    hashid: string,
    payload: SaveEvaluationPayload,
    h: Handlers = {},
): void {
    router.patch(performanceRoutes.update(hashid), payload, opts(h));
}

/** Submit a draft evaluation (locks it; every criterion must be scored). */
export function submitEvaluation(hashid: string, h: Handlers = {}): void {
    router.post(performanceRoutes.submit(hashid), {}, opts(h));
}

/** Acknowledge a submitted evaluation. */
export function acknowledgeEvaluation(hashid: string, h: Handlers = {}): void {
    router.post(performanceRoutes.acknowledge(hashid), {}, opts(h));
}

/** Delete a draft evaluation; the server redirects to the index. */
export function deleteEvaluation(hashid: string, h: Handlers = {}): void {
    router.delete(performanceRoutes.destroy(hashid), opts(h));
}
