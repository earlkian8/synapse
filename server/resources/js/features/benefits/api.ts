import { router } from '@inertiajs/react';
import { benefitsRoutes } from './routes';
import type { EnrollmentStatus } from './types';

type Handlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

export type EnrollPayload = {
    employee_id: number;
    status: EnrollmentStatus;
    reference_no?: string | null;
    enrolled_on?: string | null;
    notes?: string | null;
};

export type UpdateEnrollmentPayload = {
    status: EnrollmentStatus;
    reference_no?: string | null;
    enrolled_on?: string | null;
    ended_on?: string | null;
    notes?: string | null;
};

const opts = (h: Handlers = {}) => ({
    preserveScroll: true,
    onStart: h.onStart,
    onFinish: h.onFinish,
    onSuccess: h.onSuccess,
    onError: h.onError,
});

/** Enroll an employee in a plan. */
export function enrollEmployee(
    planHashid: string,
    payload: EnrollPayload,
    h: Handlers = {},
): void {
    router.post(benefitsRoutes.enroll(planHashid), payload, opts(h));
}

/** Update an enrollment (status / reference / dates / notes). */
export function updateEnrollment(
    id: number,
    payload: UpdateEnrollmentPayload,
    h: Handlers = {},
): void {
    router.patch(benefitsRoutes.enrollment(id), payload, opts(h));
}

/** Remove an enrollment from a plan. */
export function removeEnrollment(id: number, h: Handlers = {}): void {
    router.delete(benefitsRoutes.enrollment(id), opts(h));
}
