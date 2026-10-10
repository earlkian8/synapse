import { router } from '@inertiajs/react';
import { trainingRoutes } from './routes';
import type {
    AttendanceStatus,
    BulkAction,
    CostCategory,
    RosterStatus,
    TrainingInsightResult,
} from './types';

type Handlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

export type UpdateEnrollmentPayload = {
    status: RosterStatus;
    score?: number | null;
    remarks?: string | null;
};

const opts = (h: Handlers = {}) => ({
    preserveScroll: true,
    onStart: h.onStart,
    onFinish: h.onFinish,
    onSuccess: h.onSuccess,
    onError: h.onError,
});

/** Enroll one or more employees in a program in a single request. */
export function enrollEmployees(
    programHashid: string,
    employeeIds: number[],
    h: Handlers = {},
): void {
    router.post(
        trainingRoutes.enroll(programHashid),
        { employee_ids: employeeIds },
        opts(h),
    );
}

/** Update a single enrollment (status / score / remarks). */
export function updateEnrollment(
    id: number,
    payload: UpdateEnrollmentPayload,
    h: Handlers = {},
): void {
    router.patch(trainingRoutes.enrollment(id), payload, opts(h));
}

/** Apply one action to many enrollments at once. */
export function bulkEnrollments(
    action: BulkAction,
    enrollmentIds: number[],
    h: Handlers = {},
): void {
    router.patch(
        trainingRoutes.bulkEnrollments,
        { action, enrollment_ids: enrollmentIds },
        opts(h),
    );
}

/** Remove an enrollment from a program. */
export function removeEnrollment(id: number, h: Handlers = {}): void {
    router.delete(trainingRoutes.enrollment(id), opts(h));
}

/** Approve a request to join: the person takes a seat, if one is left. */
export function approveRequest(id: number, h: Handlers = {}): void {
    router.post(trainingRoutes.approve(id), {}, opts(h));
}

/** Decline a request to join, with an optional note the person is shown. */
export function declineRequest(
    id: number,
    note: string | null,
    h: Handlers = {},
): void {
    router.post(trainingRoutes.decline(id), { note }, opts(h));
}

export type SessionPayload = {
    title: string | null;
    session_date: string;
    starts_at: string;
    ends_at: string;
    location: string | null;
    notes: string | null;
    /** New sessions only: one per day up to this date. */
    repeat_until?: string | null;
    weekdays_only?: boolean;
};

/** Schedule a session (or a run of them), or change one. */
export function saveSession(
    target: { programHashid: string } | { sessionId: number },
    payload: SessionPayload,
    h: Handlers = {},
): void {
    if ('sessionId' in target) {
        router.patch(
            trainingRoutes.session(target.sessionId),
            payload,
            opts(h),
        );
    } else {
        router.post(
            trainingRoutes.sessions(target.programHashid),
            payload,
            opts(h),
        );
    }
}

/** Cancel a session; the attendance taken at it goes with it. */
export function deleteSession(id: number, h: Handlers = {}): void {
    router.delete(trainingRoutes.session(id), opts(h));
}

/** Take attendance: a status per enrollment, or null to clear a mark. */
export function saveAttendance(
    sessionId: number,
    marks: { enrollment_id: number; status: AttendanceStatus | null }[],
    h: Handlers = {},
): void {
    router.put(trainingRoutes.attendance(sessionId), { marks }, opts(h));
}

export type CostPayload = {
    category: CostCategory;
    description: string | null;
    amount: number;
    incurred_on: string;
};

/** Record a cost on a program, or change one. */
export function saveCost(
    target: { programHashid: string } | { costId: number },
    payload: CostPayload,
    h: Handlers = {},
): void {
    if ('costId' in target) {
        router.patch(trainingRoutes.cost(target.costId), payload, opts(h));
    } else {
        router.post(
            trainingRoutes.costs(target.programHashid),
            payload,
            opts(h),
        );
    }
}

export function deleteCost(id: number, h: Handlers = {}): void {
    router.delete(trainingRoutes.cost(id), opts(h));
}

/** Set the organisation's training budget for a year. */
export function setBudget(
    payload: { year: number; amount: number; notes: string | null },
    h: Handlers = {},
): void {
    router.post(trainingRoutes.budgetStore, payload, opts(h));
}

/** Join an open program, or ask to join one that needs approval. */
export function joinProgram(hashid: string, h: Handlers = {}): void {
    router.post(trainingRoutes.join(hashid), {}, opts(h));
}

/** Withdraw one's own request, or leave a program before it starts. */
export function withdrawEnrollment(id: number, h: Handlers = {}): void {
    router.delete(trainingRoutes.withdraw(id), opts(h));
}

/** Read Laravel's XSRF cookie so the plain fetch passes CSRF verification. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Ask the server to generate (and persist) the LLM effectiveness read for one
 * program. Failures resolve to an `unavailable` result the panel can render —
 * never a thrown error.
 */
export async function fetchTrainingInsights(
    hashid: string,
): Promise<TrainingInsightResult> {
    let response: Response;

    try {
        response = await fetch(trainingRoutes.insights(hashid), {
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
        insights?: TrainingInsightResult;
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
