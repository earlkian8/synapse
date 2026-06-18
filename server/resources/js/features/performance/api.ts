import { router } from '@inertiajs/react';
import { performanceRoutes } from './routes';

type Handlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

export type CreateEvaluationPayload = {
    employee_id: number;
    evaluation_period_id: number;
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

/** Open a new evaluation; the server redirects to its scorecard. */
export function createEvaluation(
    payload: CreateEvaluationPayload,
    h: Handlers = {},
): void {
    router.post(performanceRoutes.store, payload, opts(h));
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
