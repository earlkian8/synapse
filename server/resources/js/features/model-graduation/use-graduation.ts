import { useState } from 'react';
import { activateModel, revertModel, trainModel } from './api';
import type { ModelKey } from './types';

/** Which confirmation is open: switching to the organisation's model, or back. */
export type PendingSwitch =
    { kind: 'activate'; hashid: string } | { kind: 'revert' } | null;

/**
 * The graduation actions for one surface, with what is in flight. Switching changes
 * whose model scores everyone from the next run, so both directions go through a
 * confirmation first; training changes nothing on its own and runs straight away.
 */
export function useGraduation(model: ModelKey) {
    const [training, setTraining] = useState(false);
    const [switching, setSwitching] = useState(false);
    const [pending, setPending] = useState<PendingSwitch>(null);

    const train = () =>
        trainModel(model, {
            onStart: () => setTraining(true),
            onFinish: () => setTraining(false),
        });

    const confirm = () => {
        if (!pending) {
            return;
        }

        const handlers = {
            onStart: () => setSwitching(true),
            onFinish: () => {
                setSwitching(false);
                setPending(null);
            },
        };

        if (pending.kind === 'activate') {
            activateModel(model, pending.hashid, handlers);
        } else {
            revertModel(model, handlers);
        }
    };

    return {
        training,
        switching,
        pending,
        train,
        askToActivate: (hashid: string) =>
            setPending({ kind: 'activate', hashid }),
        askToRevert: () => setPending({ kind: 'revert' }),
        cancel: () => setPending(null),
        confirm,
    };
}
