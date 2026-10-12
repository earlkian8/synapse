import { usePage } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
import { toast } from 'sonner';
import { useAppNavigation } from '@/hooks/use-app-navigation';
import { usePermissions } from '@/hooks/use-permissions';
import { finishTour, reachStep, startRun } from './api';
import { buildNextSteps, buildTourStops } from './steps';
import { tourStore } from './store';
import { findTourTarget } from './targets';
import type {
    TourOutcome,
    TourStep,
    TourStepId,
    TourStop,
    TourTrigger,
} from './types';

const WELCOME = { kind: 'welcome', id: 'welcome' } as const;
const FINISH = { kind: 'finish', id: 'finish' } as const;

/** Bring a stop's target into view before the spotlight moves to it. */
function reveal(step: TourStep | undefined): void {
    if (step?.kind === 'stop') {
        findTourTarget(step.target)?.scrollIntoView({
            block: 'nearest',
            inline: 'nearest',
        });
    }
}

/**
 * The first-run tour: what it says for this person, where it is, and how it
 * moves. Any component can start it — the layout offers it once, the Help menu
 * on request — and every caller sees the same run, because the run itself is
 * kept in {@link tourStore}.
 */
export function useProductTour() {
    const state = useSyncExternalStore(
        tourStore.subscribe,
        tourStore.getSnapshot,
        tourStore.getServerSnapshot,
    );
    const { auth } = usePage().props;
    const { can, canAny } = usePermissions();
    const groups = useAppNavigation();

    const stops = buildTourStops({
        firstName: auth.user.first_name,
        organizationName: auth.organization?.name ?? 'your company',
        workspaceCount: auth.organizations.length,
        groups,
        can,
        canAny,
    });

    const byId = new Map<TourStepId, TourStep>([
        [WELCOME.id, WELCOME],
        [FINISH.id, FINISH],
        ...stops.map((stop): [TourStepId, TourStep] => [stop.id, stop]),
    ]);

    // The run's steps, as fixed when it started. One can only drop out if the
    // person's permissions changed underneath the tour.
    const steps = state.stepIds
        .map((id) => byId.get(id))
        .filter((step): step is TourStep => step !== undefined);
    const index = Math.min(state.index, Math.max(steps.length - 1, 0));
    const step = state.running ? (steps[index] ?? null) : null;
    const runStops = steps.filter(
        (candidate): candidate is TourStop => candidate.kind === 'stop',
    );

    // The shared prop says whether the tour is owed as of this page; the store
    // knows if it was answered since.
    const owed = (auth.tour?.owed ?? false) && !state.answered;
    const owedMode = auth.tour?.mode ?? null;
    const seenVersion = auth.tour?.seen_version ?? 0;

    const start = (trigger: TourTrigger) => {
        // Only what is on screen right now: a phone has no sidebar to point
        // at, and the assistant is not offered to every role.
        const available = stops.filter(
            (stop) => findTourTarget(stop.target) !== null,
        );

        // Offered on its own to somebody who answered an earlier version, it
        // is a "What's new" of the stops added since (ADR 0080).
        const whatsNew = trigger === 'auto' && owed && owedMode === 'whats_new';
        const shown = whatsNew
            ? available.filter((stop) => stop.since > seenVersion)
            : available;

        if (whatsNew && shown.length === 0) {
            // Nothing new on this screen (a phone without the new stop's
            // target): answer it quietly rather than show an empty tour.
            tourStore.settle();
            void finishTour('completed').catch(() => {});

            return;
        }

        const stepIds = [
            WELCOME.id,
            ...shown.map((stop) => stop.id),
            FINISH.id,
        ];

        tourStore.start({
            stepIds,
            trigger,
            kind: whatsNew ? 'whats_new' : 'full',
            records: owed,
        });

        // File the run for the per-stop numbers; the tour does not wait for it.
        const mode =
            owed && trigger === 'auto'
                ? whatsNew
                    ? 'whats_new'
                    : 'first'
                : 'replay';

        void startRun(mode, stepIds).then((id) => {
            if (id === null) {
                return;
            }

            tourStore.filed(id);

            // Somebody quick may have moved on before the run was filed.
            const { running, index } = tourStore.getSnapshot();

            if (running && index > 0) {
                reachStep(id, index);
            }
        });
    };

    const goTo = (target: number) => {
        reveal(steps[target]);
        tourStore.go(target);

        const { runId } = tourStore.getSnapshot();

        if (runId !== null) {
            reachStep(runId, target);
        }
    };

    const end = (outcome: TourOutcome) => {
        const { records, runId } = tourStore.getSnapshot();

        tourStore.end();

        if (!records && runId === null) {
            return;
        }

        // Fire and forget. Should it fail, the tour is simply offered again on
        // a later visit — nothing the person is doing depends on it. A replay
        // only closes its run; it never answers the tour.
        void finishTour(outcome, { run: runId, answers: records }).catch(
            () => {},
        );

        if (!records) {
            return;
        }

        if (outcome === 'skipped') {
            toast('Tour skipped', {
                description:
                    'Replay it any time from Help — the question mark in the top bar.',
            });
        }
    };

    return {
        running: state.running,
        offered: state.offered,
        trigger: state.trigger,
        /** The whole tour, or only what is new since last time. */
        kind: state.kind,
        owed,
        steps,
        step,
        index,
        /** The stops this run makes — what the welcome lists and counts. */
        stops: runStops,
        /** Where the current step sits among the stops, from 1; 0 off a stop. */
        stopNumber: step?.kind === 'stop' ? runStops.indexOf(step) + 1 : 0,
        nextSteps: buildNextSteps(can),
        start,
        next: () => goTo(index + 1),
        back: () => goTo(index - 1),
        skip: () => end('skipped'),
        complete: () => end('completed'),
    };
}

export type ProductTourApi = ReturnType<typeof useProductTour>;
