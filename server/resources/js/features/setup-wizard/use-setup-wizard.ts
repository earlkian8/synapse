import { router } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import { STEPS } from './constants';
import { setupWizardRoutes } from './routes';
import type { SetupProgress, SetupStep, WizardView } from './types';

export type { WizardView } from './types';

/** What the wizard itself is in the middle of, so only that button spins. */
type Busy = 'visit' | 'skip' | 'advance' | 'finish' | null;

const ORDER: SetupStep[] = STEPS.map((meta) => meta.step);

/**
 * The wizard's navigation and its whole-wizard actions.
 *
 * Which view is on screen lives in the URL (`/setup/wizard/{view}`), and the
 * server renders it: a step carries its Company Setup screen's own props, and
 * the editors on it save the way they do on that screen — post, then `back()` —
 * which lands on the same step with its list refreshed. Moving between views is
 * therefore a visit, and what is *recorded* stays the server's: `progress` is
 * re-read from props after every post, so the ladder and the finish screen can
 * never disagree with the database.
 */
export function useSetupWizard(view: WizardView, progress: SetupProgress) {
    const [busy, setBusy] = useState<Busy>(null);

    const index = ORDER.indexOf(view as SetupStep);
    const isStep = index !== -1;

    /** Open a view. The page stays mounted, so what the rail shows carries over. */
    const visit = useCallback((next: WizardView) => {
        router.get(
            setupWizardRoutes.view(next),
            {},
            {
                preserveState: true,
                onStart: () => setBusy('visit'),
                onFinish: () => setBusy(null),
            },
        );
    }, []);

    const goNext = useCallback(() => {
        visit(
            index === -1
                ? ORDER[0]
                : index === ORDER.length - 1
                  ? 'done'
                  : ORDER[index + 1],
        );
    }, [index, visit]);

    const goBack = useCallback(() => {
        if (view === 'done') {
            visit(ORDER[ORDER.length - 1]);

            return;
        }

        visit(index <= 0 ? 'intro' : ORDER[index - 1]);
    }, [index, view, visit]);

    /** Record a step as passed over, then move on. */
    const skip = useCallback(
        (step: SetupStep) => {
            router.post(
                setupWizardRoutes.skip,
                { step },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onStart: () => setBusy('skip'),
                    // On success the next visit takes over the busy state.
                    onSuccess: () => goNext(),
                    onError: () => setBusy(null),
                    onCancel: () => setBusy(null),
                },
            );
        },
        [goNext],
    );

    /**
     * Move on from a step whose work was done with its own editors. It is
     * recorded as done first, unless it already is — then this is only a visit.
     */
    const advance = useCallback(
        (step: SetupStep) => {
            if (progress.steps[step] === 'done') {
                goNext();

                return;
            }

            router.post(
                setupWizardRoutes.continue,
                { step },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onStart: () => setBusy('advance'),
                    // On success the next visit takes over the busy state.
                    onSuccess: () => goNext(),
                    onError: () => setBusy(null),
                    onCancel: () => setBusy(null),
                },
            );
        },
        [goNext, progress.steps],
    );

    /** Close setup and go to the dashboard — or straight on to inviting people. */
    const finish = useCallback((next?: 'people') => {
        setBusy('finish');

        router.post(setupWizardRoutes.finish, next ? { next } : {}, {
            onError: () => setBusy(null),
            onCancel: () => setBusy(null),
        });
    }, []);

    const counts = useMemo(() => {
        const answered = ORDER.filter(
            (step) => progress.steps[step] !== 'pending',
        ).length;

        return {
            total: ORDER.length,
            answered,
            done: ORDER.filter((step) => progress.steps[step] === 'done')
                .length,
            skipped: ORDER.filter((step) => progress.steps[step] === 'skipped')
                .length,
            percent: Math.round((answered / ORDER.length) * 100),
        };
    }, [progress.steps]);

    return {
        view,
        visit,
        /** 1-based position of the current step, or 0 outside the ladder. */
        position: isStep ? index + 1 : 0,
        isStep,
        /** Anything whole-wizard in flight — every button waits for it. */
        working: busy !== null,
        busy,
        goNext,
        goBack,
        skip,
        advance,
        finish,
        counts,
    };
}
