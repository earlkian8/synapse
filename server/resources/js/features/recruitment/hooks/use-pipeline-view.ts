import type { PipelineView } from '../types';
import { useStoredView } from './use-stored-view';

const ALLOWED: readonly PipelineView[] = ['board', 'table'];

/**
 * Remembers whether the recruiter prefers the sequential Kanban board or the
 * dense table on a posting's pipeline, persisting the choice across visits.
 */
export function usePipelineView() {
    // Keyed `.v3` so the board becomes the default for everyone, retiring any
    // stale table/grid preference persisted under previous keys.
    return useStoredView<PipelineView>(
        'recruitment.pipeline.view.v3',
        ALLOWED,
        'board',
    );
}
