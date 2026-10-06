import { useEffect, useRef, useState } from 'react';
import { MIN_QUERY_LENGTH, SearchError, searchEverything } from './api';
import type { SearchGroup, SearchStatus } from './types';

/** How long typing must pause before the palette asks. */
const DEBOUNCE_MS = 180;

/** Something to do with the answer to one search, once it arrives. */
type Pending = {
    term: string;
    run: (groups: SearchGroup[]) => void;
};

/** An answer, tagged with the search it answers. */
type Answer = {
    term: string;
    groups: SearchGroup[];
    error: string | null;
};

const NO_ANSWER: Answer = { term: '', groups: [], error: null };

/**
 * Search as somebody types: debounced, and every superseded request aborted,
 * so a slow answer to "mar" can never land on top of the answer to "maria".
 *
 * State is only ever set from the request's callbacks. Whether a search is in
 * flight is derived — the newest answer is for a different term — rather than
 * set synchronously in the effect (react-hooks/set-state-in-effect). While a
 * search runs the previous answer stays on screen, so the list does not blink
 * empty between keystrokes.
 *
 * `whenAnswered` is for Enter pressed before the answer to what was typed has
 * arrived: the palette asks to act on *that* answer, rather than on the stale
 * rows still showing. It runs from the request's callback, never from render.
 */
export function useGlobalSearch(query: string): {
    term: string;
    groups: SearchGroup[];
    status: SearchStatus;
    error: string | null;
    whenAnswered: (run: (groups: SearchGroup[]) => void) => void;
} {
    const [answer, setAnswer] = useState<Answer>(NO_ANSWER);
    const pending = useRef<Pending | null>(null);
    const term = query.trim().replace(/\s+/g, ' ');
    const searching = term.length >= MIN_QUERY_LENGTH;

    useEffect(() => {
        if (!searching) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            searchEverything(term, controller.signal)
                .then((groups) => {
                    if (controller.signal.aborted) {
                        return;
                    }

                    setAnswer({ term, groups, error: null });

                    const waiting = pending.current;
                    pending.current = null;

                    if (waiting?.term === term) {
                        waiting.run(groups);
                    }
                })
                .catch((error: unknown) => {
                    if (controller.signal.aborted) {
                        return;
                    }

                    setAnswer({
                        term,
                        groups: [],
                        error:
                            error instanceof SearchError
                                ? error.message
                                : new SearchError(0).message,
                    });
                });
        }, DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [term, searching]);

    const whenAnswered = (run: (groups: SearchGroup[]) => void) => {
        pending.current = { term, run };
    };

    if (!searching) {
        return { term, groups: [], status: 'idle', error: null, whenAnswered };
    }

    if (answer.term !== term) {
        return {
            term,
            groups: answer.groups,
            status: 'loading',
            error: null,
            whenAnswered,
        };
    }

    return {
        term,
        groups: answer.groups,
        status: answer.error ? 'error' : 'ready',
        error: answer.error,
        whenAnswered,
    };
}
