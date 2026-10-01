import { router } from '@inertiajs/react';
import { performanceForecastRoutes } from './routes';

type Handlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
};

/** Run a fresh forecast across all active employees. */
export function runForecast(h: Handlers = {}): void {
    router.post(
        performanceForecastRoutes.store,
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
        performanceForecastRoutes.index,
        { run: hashid },
        { preserveScroll: true, preserveState: true },
    );
}

/** Delete a historical forecast run. */
export function deleteRun(hashid: string, h: Handlers = {}): void {
    router.delete(performanceForecastRoutes.destroy(hashid), {
        preserveScroll: true,
        onStart: h.onStart,
        onFinish: h.onFinish,
        onSuccess: h.onSuccess,
    });
}
