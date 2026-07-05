import { useCallback, useState } from 'react';

/** How the events overview is laid out: a dense table or status-grouped cards. */
export type EventsView = 'table' | 'grid';

const KEY = 'events.overview.view';
const ALLOWED: readonly EventsView[] = ['table', 'grid'];

/**
 * Remembers whether the user prefers the table or card layout for the events
 * overview, persisting the choice per browser. Defaults to the table.
 */
export function useEventsView() {
    const read = (): EventsView => {
        if (typeof window === 'undefined') {
            return 'table';
        }

        const saved = window.localStorage.getItem(KEY);

        return saved && (ALLOWED as readonly string[]).includes(saved)
            ? (saved as EventsView)
            : 'table';
    };

    const [view, setView] = useState<EventsView>(read);

    const changeView = useCallback((next: EventsView) => {
        setView(next);

        if (typeof window !== 'undefined') {
            window.localStorage.setItem(KEY, next);
        }
    }, []);

    return { view, changeView };
}
