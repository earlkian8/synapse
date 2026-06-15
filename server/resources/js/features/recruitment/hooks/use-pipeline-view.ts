import type { PipelineView } from '../types';
import { useStoredView } from './use-stored-view';

const ALLOWED: readonly PipelineView[] = ['table', 'grid'];

/**
 * Remembers whether the recruiter prefers the flat table or the card grid on a
 * posting's pipeline, persisting the choice across visits.
 */
export function usePipelineView() {
    return useStoredView<PipelineView>(
        'recruitment.pipeline.view',
        ALLOWED,
        'table',
    );
}
