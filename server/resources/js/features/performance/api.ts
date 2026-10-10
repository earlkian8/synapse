import { router } from '@inertiajs/react';
import { performanceRoutes } from './routes';
import type {
    GoalHealth,
    GoalMeasure,
    PerformanceGoal,
    PerformanceInsightResult,
} from './types';

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
    /** Ask each person for a self-review, and their manager for a review. */
    self_reviews: boolean;
    manager_reviews: boolean;
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

/** Record a sign-off on the employee's behalf (paper or in person). */
export function acknowledgeEvaluation(hashid: string, h: Handlers = {}): void {
    router.post(performanceRoutes.acknowledge(hashid), {}, opts(h));
}

/** Delete a draft evaluation; the server redirects to the index. */
export function deleteEvaluation(hashid: string, h: Handlers = {}): void {
    router.delete(performanceRoutes.destroy(hashid), opts(h));
}

// ── Reviews (ADR 0072) ──────────────────────────────────────────────────────

/** Ask colleagues to review an appraisal. */
export function requestReviews(
    hashid: string,
    payload: { reviewer_ids: number[]; due_on: string | null },
    h: Handlers = {},
): void {
    router.post(performanceRoutes.requestReviews(hashid), payload, opts(h));
}

export function cancelReview(hashid: string, h: Handlers = {}): void {
    router.post(performanceRoutes.reviewCancel(hashid), {}, opts(h));
}

export function remindReview(hashid: string, h: Handlers = {}): void {
    router.post(performanceRoutes.reviewRemind(hashid), {}, opts(h));
}

export type ReviewPayload = {
    scores: ScoreLinePayload[];
    strengths: string | null;
    improvements: string | null;
};

/** Save a reviewer's answers (any part of them). */
export function saveReview(
    hashid: string,
    payload: ReviewPayload,
    h: Handlers = {},
): void {
    router.patch(performanceRoutes.review(hashid), payload, opts(h));
}

/** Save and hand in a review. */
export function submitReview(
    hashid: string,
    payload: ReviewPayload,
    h: Handlers = {},
): void {
    router.post(performanceRoutes.reviewSubmit(hashid), payload, opts(h));
}

export function declineReview(
    hashid: string,
    reason: string | null,
    h: Handlers = {},
): void {
    router.post(performanceRoutes.reviewDecline(hashid), { reason }, opts(h));
}

/** The employee acknowledges their own appraisal, with an optional comment. */
export function acknowledgeOwnAppraisal(
    hashid: string,
    comment: string | null,
    h: Handlers = {},
): void {
    router.post(performanceRoutes.myAcknowledge(hashid), { comment }, opts(h));
}

// ── Goals (ADR 0073) ────────────────────────────────────────────────────────

export type GoalPayload = {
    employee_ids?: number[];
    evaluation_period_id?: number;
    goal_template_id?: number | null;
    title: string;
    description: string | null;
    measure: GoalMeasure;
    start_value: number | null;
    target_value: number | null;
    unit: string | null;
    weight: number | null;
    due_on: string | null;
};

/** HR sets a goal for one or more people. */
export function createGoal(payload: GoalPayload, h: Handlers = {}): void {
    router.post(performanceRoutes.goalsStore, payload, opts(h));
}

/** Someone adds a goal of their own. */
export function createOwnGoal(payload: GoalPayload, h: Handlers = {}): void {
    router.post(performanceRoutes.myGoalsStore, payload, opts(h));
}

export function updateGoal(
    hashid: string,
    payload: GoalPayload,
    h: Handlers = {},
): void {
    router.patch(performanceRoutes.goal(hashid), payload, opts(h));
}

export type CheckInPayload = {
    value: number;
    health: GoalHealth;
    note: string | null;
};

/** A check-in: by HR (`own` false) or by the goal's owner. */
export function checkInGoal(
    hashid: string,
    payload: CheckInPayload,
    own: boolean,
    h: Handlers = {},
): void {
    router.post(
        own
            ? performanceRoutes.myGoalCheckIn(hashid)
            : performanceRoutes.goalCheckIn(hashid),
        payload,
        opts(h),
    );
}

export function setGoalStatus(
    hashid: string,
    status: PerformanceGoal['status'],
    h: Handlers = {},
): void {
    router.post(performanceRoutes.goalStatus(hashid), { status }, opts(h));
}

export function deleteGoal(
    hashid: string,
    own: boolean,
    h: Handlers = {},
): void {
    router.delete(
        own
            ? performanceRoutes.myGoalDestroy(hashid)
            : performanceRoutes.goal(hashid),
        opts(h),
    );
}

// ── Calibration (ADR 0073) ──────────────────────────────────────────────────

export type SessionPayload = {
    name: string;
    evaluation_period_id?: number;
    department_ids?: number[] | null;
    scheduled_for: string | null;
    notes: string | null;
    participant_ids: number[];
};

export function createSession(payload: SessionPayload, h: Handlers = {}): void {
    router.post(performanceRoutes.calibrationStore, payload, opts(h));
}

export function updateSession(
    hashid: string,
    payload: SessionPayload,
    h: Handlers = {},
): void {
    router.patch(performanceRoutes.session(hashid), payload, opts(h));
}

export function adjustRating(
    hashid: string,
    payload: { evaluation_id: number; band: string; reason: string },
    h: Handlers = {},
): void {
    router.post(performanceRoutes.sessionAdjust(hashid), payload, opts(h));
}

export function completeSession(hashid: string, h: Handlers = {}): void {
    router.post(performanceRoutes.sessionComplete(hashid), {}, opts(h));
}

export function cancelSession(hashid: string, h: Handlers = {}): void {
    router.post(performanceRoutes.sessionCancel(hashid), {}, opts(h));
}
