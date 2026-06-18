import { router } from '@inertiajs/react';
import { awardsRoutes } from './routes';

type Handlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

export type GiveAwardPayload = {
    employee_id: number;
    award_type_id: number;
    awarded_on: string;
    reason?: string | null;
};

export type UpdateAwardPayload = {
    award_type_id: number;
    awarded_on: string;
    reason?: string | null;
};

const opts = (h: Handlers = {}) => ({
    preserveScroll: true,
    onStart: h.onStart,
    onFinish: h.onFinish,
    onSuccess: h.onSuccess,
    onError: h.onError,
});

/** Give a recognition to an employee. */
export function giveAward(payload: GiveAwardPayload, h: Handlers = {}): void {
    router.post(awardsRoutes.store, payload, opts(h));
}

/** Update an award (type / date / reason). */
export function updateAward(
    id: number,
    payload: UpdateAwardPayload,
    h: Handlers = {},
): void {
    router.patch(awardsRoutes.award(id), payload, opts(h));
}

/** Remove an award. */
export function removeAward(id: number, h: Handlers = {}): void {
    router.delete(awardsRoutes.award(id), opts(h));
}
