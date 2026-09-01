import * as mock from './mock-engine';
import type { GraduationCheck } from './types';

type CheckHandlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: (check: GraduationCheck) => void;
};

type DeleteHandlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
};

/**
 * A short artificial delay so "Re-check readiness" still feels like it is
 * querying records — Model Graduation is a frontend-only demo (see
 * mock-engine.ts), so the check itself is instant.
 */
const SIMULATED_DELAY_MS = 700;

/** Re-evaluate every graduation requirement against the current records. */
export function runCheck(h: CheckHandlers = {}): void {
    h.onStart?.();

    window.setTimeout(() => {
        const check = mock.runCheck();
        h.onFinish?.();
        h.onSuccess?.(check);
    }, SIMULATED_DELAY_MS);
}

/** Delete a historical readiness check. */
export function deleteCheck(hashid: string, h: DeleteHandlers = {}): void {
    h.onStart?.();

    window.setTimeout(() => {
        mock.deleteCheck(hashid);
        h.onFinish?.();
        h.onSuccess?.();
    }, 250);
}
