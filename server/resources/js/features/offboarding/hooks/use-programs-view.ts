import { useCallback, useState } from 'react';

/** How the clearance templates are laid out: a dense table or a card grid. */
export type ProgramsView = 'table' | 'grid';

const KEY = 'offboarding.programs.view';
const ALLOWED: readonly ProgramsView[] = ['table', 'grid'];

/**
 * Remembers whether the admin prefers the table or card layout for clearance
 * templates, persisting the choice per browser. Defaults to the table.
 */
export function useProgramsView() {
    const read = (): ProgramsView => {
        if (typeof window === 'undefined') {
            return 'table';
        }

        const saved = window.localStorage.getItem(KEY);

        return saved && (ALLOWED as readonly string[]).includes(saved)
            ? (saved as ProgramsView)
            : 'table';
    };

    const [view, setView] = useState<ProgramsView>(read);

    const changeView = useCallback((next: ProgramsView) => {
        setView(next);

        if (typeof window !== 'undefined') {
            window.localStorage.setItem(KEY, next);
        }
    }, []);

    return { view, changeView };
}
