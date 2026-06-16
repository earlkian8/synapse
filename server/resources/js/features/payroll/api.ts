import { router } from '@inertiajs/react';
import { payrollRoutes } from './routes';

type Handlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

export type NewRunPayload = {
    name: string;
    start_date: string;
    end_date: string;
    pay_date: string;
};

const opts = (h: Handlers = {}) => ({
    preserveScroll: true,
    onStart: h.onStart,
    onFinish: h.onFinish,
    onSuccess: h.onSuccess,
    onError: h.onError,
});

/** Create a payroll run and generate its payslips. */
export function createRun(payload: NewRunPayload, h: Handlers = {}): void {
    router.post(payrollRoutes.store, payload, opts(h));
}

/** Re-process an open run's payslips. */
export function processRun(hashid: string, h: Handlers = {}): void {
    router.patch(payrollRoutes.process(hashid), {}, opts(h));
}

/** Finalize a run, locking its payslips. */
export function finalizeRun(hashid: string, h: Handlers = {}): void {
    router.patch(payrollRoutes.finalize(hashid), {}, opts(h));
}

/** Mark a run paid and release its payslips. */
export function markPaid(hashid: string, h: Handlers = {}): void {
    router.patch(payrollRoutes.paid(hashid), {}, opts(h));
}

/** Delete an open run. */
export function deleteRun(hashid: string, h: Handlers = {}): void {
    router.delete(payrollRoutes.destroy(hashid), opts(h));
}
