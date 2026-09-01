import * as mock from './mock-engine';
import type { RiskRun } from './types';

type RunHandlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: (run: RiskRun) => void;
};

type DeleteHandlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
};

/**
 * A short artificial delay so "Run assessment" still feels like it's doing
 * work — Attrition Risk is a frontend-only demo (see mock-engine.ts), so the
 * scoring itself is instant.
 */
const SIMULATED_DELAY_MS = 650;

/** Run a fresh (simulated) attrition-risk assessment across the demo roster. */
export function runAssessment(h: RunHandlers = {}): void {
    h.onStart?.();

    window.setTimeout(() => {
        const run = mock.runAssessment();
        h.onFinish?.();
        h.onSuccess?.(run);
    }, SIMULATED_DELAY_MS);
}

/** Delete a historical (simulated) assessment run. */
export function deleteRun(hashid: string, h: DeleteHandlers = {}): void {
    h.onStart?.();

    window.setTimeout(() => {
        mock.deleteRun(hashid);
        h.onFinish?.();
        h.onSuccess?.();
    }, 250);
}
