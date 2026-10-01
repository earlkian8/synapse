import type { TourStepId, TourTrigger } from './types';

/**
 * The tour's running state, kept outside React.
 *
 * It lives at module level (like the appearance store) for two reasons: the
 * Help menu in the top bar starts the tour that the layout renders, and a
 * persistent layout is still remounted when a page swaps it (settings pages
 * nest theirs) — a tour in progress must not be lost to that.
 */
export type TourState = {
    running: boolean;
    /** Where in `stepIds` the tour is. */
    index: number;
    /** The steps this run takes, fixed when it starts. */
    stepIds: TourStepId[];
    trigger: TourTrigger;
    /** Whether ending this run answers the tour the server says is owed. */
    records: boolean;
    /** The tour has already been offered on its own since the page loaded. */
    offered: boolean;
    /**
     * The owed tour was answered since the page loaded. The shared props say
     * so from the next visit on; until then this does.
     */
    answered: boolean;
};

const initialState: TourState = {
    running: false,
    index: 0,
    stepIds: [],
    trigger: 'auto',
    records: false,
    offered: false,
    answered: false,
};

const listeners = new Set<() => void>();
let state: TourState = initialState;

function update(next: Partial<TourState>): void {
    state = { ...state, ...next };
    listeners.forEach((listener) => listener());
}

export const tourStore = {
    subscribe(listener: () => void): () => void {
        listeners.add(listener);

        return () => listeners.delete(listener);
    },

    getSnapshot(): TourState {
        return state;
    },

    getServerSnapshot(): TourState {
        return initialState;
    },

    start(run: {
        stepIds: TourStepId[];
        trigger: TourTrigger;
        records: boolean;
    }): void {
        if (state.running || run.stepIds.length === 0) {
            return;
        }

        update({ ...run, running: true, index: 0, offered: true });
    },

    go(index: number): void {
        if (!state.running) {
            return;
        }

        update({
            index: Math.min(Math.max(index, 0), state.stepIds.length - 1),
        });
    },

    end(): void {
        update({
            running: false,
            index: 0,
            stepIds: [],
            records: false,
            answered: state.answered || state.records,
        });
    },
};
