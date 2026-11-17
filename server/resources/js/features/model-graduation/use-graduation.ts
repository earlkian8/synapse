import { useState } from 'react';
import { activateModel, dismissModel, revertModel, trainModel } from './api';
import type { ModelKey } from './types';

/**
 * Which confirmation was asked for: switching to the organisation's model (one that
 * did not pass its check, too — ADR 0107), keeping the model in use instead, or
 * switching back to the general model.
 */
export type PendingSwitch =
    | { kind: 'activate'; hashid: string; passed: boolean }
    | { kind: 'dismiss'; hashid: string }
    | { kind: 'revert' }
    | null;

/**
 * The graduation actions for one surface, with what is in flight. Every decision on
 * whose model scores everyone goes through a confirmation first; training changes
 * nothing on its own and runs straight away. Switching to a model that did not pass
 * its check also needs the reader to tick that they understand it — what the server
 * receives as `despite_check`.
 *
 * The last request outlives its confirmation closing, so the dialog keeps its own
 * wording while it fades out instead of flipping to the other direction's.
 */
export function useGraduation(model: ModelKey) {
    const [training, setTraining] = useState(false);
    const [switching, setSwitching] = useState(false);
    const [pending, setPending] = useState<PendingSwitch>(null);
    const [confirming, setConfirming] = useState(false);
    const [acknowledged, setAcknowledged] = useState(false);

    const train = () =>
        trainModel(model, {
            onStart: () => setTraining(true),
            onFinish: () => setTraining(false),
        });

    const ask = (next: Exclude<PendingSwitch, null>) => {
        setPending(next);
        setAcknowledged(false);
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
            activateModel(
                model,
                pending.hashid,
                !pending.passed && acknowledged,
                handlers,
            );
        } else if (pending.kind === 'dismiss') {
            dismissModel(model, pending.hashid, handlers);
        } else {
            revertModel(model, handlers);
        }
    };

    return {
        training,
        switching,
        pending,
        confirming,
        /** For a model that did not pass: whether the reader ticked that they understand. */
        acknowledged,
        setAcknowledged,
        /** Whether the confirmation may go ahead. */
        canConfirm:
            pending?.kind !== 'activate' || pending.passed || acknowledged,
        train,
        askToActivate: (hashid: string, passed: boolean) =>
            ask({ kind: 'activate', hashid, passed }),
        askToDismiss: (hashid: string) => ask({ kind: 'dismiss', hashid }),
        askToRevert: () => ask({ kind: 'revert' }),
        cancel: () => setConfirming(false),
        confirm,
    };
}
