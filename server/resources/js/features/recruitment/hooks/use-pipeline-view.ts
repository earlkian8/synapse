import type { PipelineView } from '../types';
import { useStoredView } from './use-stored-view';

const ALLOWED: readonly PipelineView[] = ['board', 'table'];

/**
 * Remembers whether the recruiter prefers the kanban board or the flat table on
 * a posting's pipeline, persisting the choice across visits.
 */
export function usePipelineView() {
    return useStoredView<PipelineView>(
        'recruitment.pipeline.view',
        ALLOWED,
        'board',
    );
}
