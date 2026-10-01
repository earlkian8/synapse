import type { Stage } from '../types';

/**
 * Which model scored a run, beside its timestamp — said once the organisation has
 * had a model of its own, when "the general model" stops going without saying.
 */
export function ScoredBy({
    scoredBy,
    stage,
}: {
    scoredBy: 'own' | 'general';
    stage: Stage;
}) {
    if (scoredBy === 'general' && stage !== 'graduated') {
        return null;
    }

    return (
        <>
            {' · scored by '}
            <span className="font-medium text-foreground">
                {scoredBy === 'own' ? 'your own model' : 'the general model'}
            </span>
        </>
    );
}
