import { router } from '@inertiajs/react';
import { attritionRiskRoutes } from './routes';

type Handlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
};

/** Run a fresh attrition-risk assessment across all active employees. */
export function runAssessment(h: Handlers = {}): void {
    router.post(
        attritionRiskRoutes.store,
        {},
        {
            preserveScroll: true,
            onStart: h.onStart,
            onFinish: h.onFinish,
            onSuccess: h.onSuccess,
        },
    );
}

/** Switch the viewed run (history selector); preserves scroll + UI state. */
export function viewRun(hashid: string): void {
    router.get(
        attritionRiskRoutes.index,
        { run: hashid },
        { preserveScroll: true, preserveState: true },
    );
}

/** Delete a historical assessment run. */
export function deleteRun(hashid: string, h: Handlers = {}): void {
    router.delete(attritionRiskRoutes.destroy(hashid), {
        preserveScroll: true,
        onStart: h.onStart,
        onFinish: h.onFinish,
        onSuccess: h.onSuccess,
    });
}
