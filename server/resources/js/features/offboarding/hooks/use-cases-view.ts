import { useCallback, useState } from 'react';

/** How the offboarding board is laid out: a dense table or a card grid. */
export type CasesView = 'table' | 'grid';

const KEY = 'offboarding.cases.view';
const ALLOWED: readonly CasesView[] = ['table', 'grid'];

/**
 * Remembers whether the user prefers the table or card layout for the
 * offboarding board, persisting the choice per browser. Defaults to the table.
 */
export function useCasesView() {
    const read = (): CasesView => {
        if (typeof window === 'undefined') {
            return 'table';
        }

        const saved = window.localStorage.getItem(KEY);

        return saved && (ALLOWED as readonly string[]).includes(saved)
            ? (saved as CasesView)
            : 'table';
    };

    const [view, setView] = useState<CasesView>(read);

    const changeView = useCallback((next: CasesView) => {
        setView(next);

        if (typeof window !== 'undefined') {
            window.localStorage.setItem(KEY, next);
        }
    }, []);

    return { view, changeView };
}
