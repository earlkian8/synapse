import { cn } from '@/lib/utils';
import { MOVABLE_STAGES } from '../constants';
import type { Stage } from '../types';

/**
 * The hiring pipeline as a stepper: where this candidate stands, and — for
 * anyone who may manage the pipeline — the control that moves them. Terminal
 * candidates (hired, rejected) are never shown one; they have left the pipeline.
 */
export function StageStepper({
    stage,
    canMove,
    onMove,
}: {
    stage: Stage;
    canMove: boolean;
    onMove: (stage: Stage) => void;
}) {
    const currentIndex = MOVABLE_STAGES.findIndex((s) => s.value === stage);

    return (
        <ol className="flex items-stretch gap-1 rounded-xl border border-border bg-muted/40 p-1">
            {MOVABLE_STAGES.map((step, index) => {
                const isCurrent = step.value === stage;
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
                    <li key={step.value} className="flex-1">
                        {canMove && !isCurrent ? (
                            <button
                                type="button"
                                className={cn(classes, 'cursor-pointer')}
                                onClick={() => onMove(step.value)}
                            >
                                {step.label}
                            </button>
                        ) : (
                            <span
                                className={cn(classes, 'block')}
                                aria-current={isCurrent ? 'step' : undefined}
                            >
                                {step.label}
                            </span>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
