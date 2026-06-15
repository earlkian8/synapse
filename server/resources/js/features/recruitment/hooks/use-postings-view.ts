import { useCallback, useState } from 'react';
import type { PostingsView } from '../types';

const STORAGE_KEY = 'recruitment.postings.view';

function readStored(): PostingsView {
    if (typeof window === 'undefined') {
        return 'table';
    }

    const saved = window.localStorage.getItem(STORAGE_KEY);

    return saved === 'grid' || saved === 'table' ? saved : 'table';
}

/**
 * Remembers whether the recruiter prefers the postings table or the card grid,
 * persisting the choice across visits via localStorage.
 */
export function usePostingsView() {
    const [view, setView] = useState<PostingsView>(readStored);

    const changeView = useCallback((next: PostingsView) => {
        setView(next);

        if (typeof window !== 'undefined') {
            window.localStorage.setItem(STORAGE_KEY, next);
        }
    }, []);

    return { view, changeView };
}
