import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import type { PredictionContext } from '../types';
import type { PredictionContextState } from '../use-prediction-context';

/**
 * Renders what depends on the person's fetched context (ADR 0099): a quiet
 * placeholder while it loads, a plain line if it could not be loaded — the
 * prediction itself is still shown around it — and the content once it is there.
 */
export function ContextGate({
    state,
    lines = 3,
    children,
}: {
    state: PredictionContextState;
    /** How tall the placeholder is, in rows. */
    lines?: number;
    children: (context: PredictionContext) => ReactNode;
}) {
    if (state.status === 'loading') {
        return (
            <div className="flex flex-col gap-2" aria-busy="true">
                <span className="sr-only">Loading their record…</span>
                {Array.from({ length: lines }, (_, i) => (
                    <Skeleton
                        key={i}
                        className="h-10 w-full rounded-lg"
                        style={{ opacity: 1 - i * 0.2 }}
                    />
                ))}
            </div>
        );
    }

    if (state.status === 'failed') {
        return (
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                <p className="text-sm text-muted-foreground">
                    Their history and record could not be loaded.
                </p>
                <Button size="sm" variant="outline" onClick={state.retry}>
                    Try again
                </Button>
            </div>
        );
    }

    return <>{children(state.context)}</>;
}
