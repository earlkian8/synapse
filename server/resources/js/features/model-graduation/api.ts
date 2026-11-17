import { router } from '@inertiajs/react';
import { graduationRoutes } from './routes';
import type { ModelKey } from './types';

type Handlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
};

const options = (h: Handlers) => ({
    preserveScroll: true,
    onStart: h.onStart,
    onFinish: h.onFinish,
    onSuccess: h.onSuccess,
});

/** Train the surface's model on the organisation's own records, and check it. */
export function trainModel(model: ModelKey, h: Handlers = {}): void {
    router.post(graduationRoutes.train(model), {}, options(h));
}

/**
 * Switch the surface to the model on offer. One that did not pass its check needs
 * `despiteCheck` — the acknowledgement that it is wanted anyway (ADR 0107).
 */
export function activateModel(
    model: ModelKey,
    hashid: string,
    despiteCheck: boolean,
    h: Handlers = {},
): void {
    router.post(
        graduationRoutes.activate(model, hashid),
        { despite_check: despiteCheck },
        options(h),
    );
}

/** Keep the model in use, and set the one on offer aside. */
export function dismissModel(
    model: ModelKey,
    hashid: string,
    h: Handlers = {},
): void {
    router.post(graduationRoutes.dismiss(model, hashid), {}, options(h));
}

/** Switch the surface back to the general model. */
export function revertModel(model: ModelKey, h: Handlers = {}): void {
    router.delete(graduationRoutes.revert(model), options(h));
}
