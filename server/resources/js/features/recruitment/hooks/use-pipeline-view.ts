import type { PipelineView } from '../types';
import { useStoredView } from './use-stored-view';

const ALLOWED: readonly PipelineView[] = ['table', 'grid'];

/**
 * Remembers whether the recruiter prefers the flat table or the card grid on a
 * posting's pipeline, persisting the choice across visits.
 */
export function usePipelineView() {
    // Keyed `.v2` so the table default takes effect for everyone, retiring any
    // stale grid preference persisted under the previous key.
    return useStoredView<PipelineView>(
        'recruitment.pipeline.view.v2',
        ALLOWED,
        'table',
    );
}
