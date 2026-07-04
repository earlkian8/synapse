import { router } from '@inertiajs/react';
import { trainingRoutes } from './routes';
import type {
    BulkAction,
    TrainingEnrollmentStatus,
    TrainingInsightResult,
} from './types';

type Handlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

export type UpdateEnrollmentPayload = {
    status: TrainingEnrollmentStatus;
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
