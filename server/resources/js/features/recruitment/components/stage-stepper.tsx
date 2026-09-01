import { cn } from '@/lib/utils';
import type { PipelineStage } from '../types';

/**
 * The hiring pipeline as a stepper: where this candidate stands, and — for
 * anyone who may manage the pipeline — the control that moves them. Built
 * from the posting's own pipeline, so it reads correctly for any custom stage
 * list. Terminal candidates (won/lost) are never shown one; they have left
 * the open pipeline.
 */
export function StageStepper({
    stages,
    currentStageId,
    canMove,
    onMove,
}: {
    /** The posting's open-kind stages, in order. */
    stages: PipelineStage[];
    currentStageId: number;
    canMove: boolean;
    onMove: (stageId: number) => void;
}) {
    const currentIndex = stages.findIndex((s) => s.id === currentStageId);

    return (
        <ol className="flex items-stretch gap-1 rounded-xl border border-border bg-muted/40 p-1">
            {stages.map((step, index) => {
                const isCurrent = step.id === currentStageId;
                const isPast = currentIndex > -1 && index < currentIndex;

                const classes = cn(
                    'w-full rounded-lg px-2 py-1.5 text-center text-xs font-medium transition-colors',
                    isCurrent && 'bg-[#0F2044] text-white shadow-xs',
                    !isCurrent && isPast && 'bg-background text-foreground',
                    !isCurrent && !isPast && 'text-muted-foreground',
                    canMove &&
                        !isCurrent &&
                        'hover:bg-background hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                );

                return (
                    <li key={step.id} className="flex-1">
                        {canMove && !isCurrent ? (
                            <button
                                type="button"
                                className={cn(classes, 'cursor-pointer')}
                                onClick={() => onMove(step.id)}
                            >
                                {step.name}
                            </button>
                        ) : (
                            <span
                                className={cn(classes, 'block')}
                                aria-current={isCurrent ? 'step' : undefined}
                            >
                                {step.name}
                            </span>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
