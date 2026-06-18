import { router } from '@inertiajs/react';
import { trainingRoutes } from './routes';
import type { TrainingEnrollmentStatus } from './types';

type Handlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

export type EnrollPayload = {
    employee_id: number;
    status: TrainingEnrollmentStatus;
    score?: number | null;
    remarks?: string | null;
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

/** Enroll an employee in a program. */
export function enrollEmployee(
    programHashid: string,
    payload: EnrollPayload,
    h: Handlers = {},
): void {
    router.post(trainingRoutes.enroll(programHashid), payload, opts(h));
}

/** Update an enrollment (status / score / remarks). */
export function updateEnrollment(
    id: number,
    payload: UpdateEnrollmentPayload,
    h: Handlers = {},
): void {
    router.patch(trainingRoutes.enrollment(id), payload, opts(h));
}

/** Remove an enrollment from a program. */
export function removeEnrollment(id: number, h: Handlers = {}): void {
    router.delete(trainingRoutes.enrollment(id), opts(h));
}
