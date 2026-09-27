import { useState } from 'react';
import { activateModel, revertModel, trainModel } from './api';
import type { ModelKey } from './types';

/** Which confirmation was asked for: switching to the organisation's model, or back. */
export type PendingSwitch =
    { kind: 'activate'; hashid: string } | { kind: 'revert' } | null;

/**
 * The graduation actions for one surface, with what is in flight. Switching changes
 * whose model scores everyone from the next run, so both directions go through a
 * confirmation first; training changes nothing on its own and runs straight away.
 *
 * The last request outlives its confirmation closing, so the dialog keeps its own
 * wording while it fades out instead of flipping to the other direction's.
 */
export function useGraduation(model: ModelKey) {
    const [training, setTraining] = useState(false);
    const [switching, setSwitching] = useState(false);
    const [pending, setPending] = useState<PendingSwitch>(null);
    const [confirming, setConfirming] = useState(false);

    const train = () =>
        trainModel(model, {
            onStart: () => setTraining(true),
            onFinish: () => setTraining(false),
        });

    const ask = (next: Exclude<PendingSwitch, null>) => {
        setPending(next);
        setConfirming(true);
    };

    const confirm = () => {
        if (!pending) {
            return;
        }

        const handlers = {
            onStart: () => setSwitching(true),
            onFinish: () => {
                setSwitching(false);
                setConfirming(false);
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
        confirming,
        train,
        askToActivate: (hashid: string) => ask({ kind: 'activate', hashid }),
        askToRevert: () => ask({ kind: 'revert' }),
        cancel: () => setConfirming(false),
        confirm,
    };
}
