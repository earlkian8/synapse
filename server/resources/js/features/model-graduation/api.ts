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

/** Switch the surface to a model that passed its check. */
export function activateModel(
    model: ModelKey,
    hashid: string,
    h: Handlers = {},
): void {
    router.post(graduationRoutes.activate(model, hashid), {}, options(h));
}

/** Switch the surface back to the general model. */
export function revertModel(model: ModelKey, h: Handlers = {}): void {
    router.delete(graduationRoutes.revert(model), options(h));
}
