import type { PostingsView } from '../types';
import { useStoredView } from './use-stored-view';

const ALLOWED: readonly PostingsView[] = ['table', 'grid'];

/**
 * Remembers whether the recruiter prefers the postings table or the card grid,
 * persisting the choice across visits.
 */
export function usePostingsView() {
    return useStoredView<PostingsView>(
        'recruitment.postings.view',
        ALLOWED,
        'table',
    );
}
