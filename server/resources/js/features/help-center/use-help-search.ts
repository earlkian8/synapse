import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { helpRoutes } from './routes';

/** How long typing pauses before the results page refreshes. */
const DEBOUNCE_MS = 250;

/** The props a refreshed search swaps in — the categories never change. */
const RESULT_PROPS = ['query', 'results', 'partial', 'terms'];

/**
 * A Help Center search box's state. Everywhere it submits on Enter; on the
 * results page itself (`live`) it also refreshes the results as the person
 * types, replacing the history entry rather than stacking one per keystroke.
 */
export function useHelpSearch(initial: string, live: boolean) {
    const [query, setQuery] = useState(initial);
    const timer = useRef<number | undefined>(undefined);

    // Never let a pending refresh fire after the box is gone.
    useEffect(() => () => window.clearTimeout(timer.current), []);

    const visit = (value: string, refresh: boolean) => {
        const q = value.trim();

        router.get(
            helpRoutes.search,
            q === '' ? {} : { q },
            refresh
                ? {
                      only: RESULT_PROPS,
                      preserveState: true,
                      preserveScroll: true,
                      replace: true,
                  }
                : {},
        );
    };

    const change = (value: string) => {
        setQuery(value);

        if (!live) {
            return;
        }

        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(
            () => visit(value, true),
            DEBOUNCE_MS,
        );
    };

    const submit = () => {
        window.clearTimeout(timer.current);
        visit(query, live);
    };

    return { query, change, submit };
}
