import type { PipelineView } from '../types';
import { useStoredView } from './use-stored-view';

const ALLOWED: readonly PipelineView[] = ['table', 'board'];

/**
 * Remembers whether the recruiter works the pipeline as the table — the
 * default, like every other list — or the Kanban board, per browser.
 */
export function usePipelineView() {
    // Keyed `.v4` so the table becomes the default for everyone, retiring the
    // board default persisted under the previous key.
    return useStoredView<PipelineView>(
        'recruitment.pipeline.view.v4',
        ALLOWED,
        'table',
    );
}
