import { Check, Lock } from 'lucide-react';
import { cn } from '@/lib/utils';
import { STAGE_COPY, STAGE_ORDER } from '../constants';
import type { Stage } from '../types';

type Position = 'done' | 'current' | 'upcoming';

function positionFor(stage: Stage, active: Stage): Position {
    const index = STAGE_ORDER.indexOf(stage);
    const activeIndex = STAGE_ORDER.indexOf(active);

    if (index < activeIndex) {
        return 'done';
    }

    return index === activeIndex ? 'current' : 'upcoming';
}

/**
 * The three steps a surface moves through, as a numbered rail. The order is real —
 * a surface cannot have its own model before it has collected the history to train
 * one — so the steps are numbered.
 *
 * The step into "your own model" carries a lock until the surface has graduated:
 * that lock is the requirement list below, and the rail says so.
 */
export function StageRail({ stage }: { stage: Stage }) {
    const lastIndex = STAGE_ORDER.length - 1;

    return (
        <ol className="flex flex-col md:grid md:grid-cols-3">
            {STAGE_ORDER.map((item, index) => (
                <StageNode
                    key={item}
                    number={index + 1}
                    stage={item}
                    position={positionFor(item, stage)}
                    isLast={index === lastIndex}
                    /* The step into the last stage is the gate. */
                    gated={index === lastIndex - 1 && stage !== 'graduated'}
                />
            ))}
        </ol>
    );
}

function StageNode({
    number,
    stage,
    position,
    isLast,
    gated,
}: {
    number: number;
    stage: Stage;
    position: Position;
    isLast: boolean;
    gated: boolean;
}) {
    const copy = STAGE_COPY[stage];

    return (
        <li
            className="flex gap-3 md:flex-col md:gap-0"
            aria-current={position === 'current' ? 'step' : undefined}
        >
            {/* Rail: runs downward on mobile, across from md up. */}
            <div className="flex w-6 shrink-0 flex-col items-center md:h-6 md:w-full md:flex-row">
                <Marker number={number} position={position} />
                {!isLast && (
                    <Connector done={position === 'done'} gated={gated} />
                )}
            </div>

            <div className="min-w-0 pb-5 md:mt-3 md:pr-6 md:pb-0">
                <p
                    className={cn(
                        'flex flex-wrap items-center gap-2 text-sm font-medium',
                        position === 'upcoming' && 'text-muted-foreground',
                    )}
                >
                    {copy.label}
                    {position === 'current' && (
                        <span className="rounded-full bg-[#0ABFBF]/15 px-2 py-0.5 text-[11px] font-medium text-teal-700 dark:text-[#0ABFBF]">
                            You are here
                        </span>
                    )}
                </p>
                <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                    {copy.description}
                </p>
            </div>
        </li>
    );
}

/** The line between two steps, carrying the lock on the gated one. */
function Connector({ done, gated }: { done: boolean; gated: boolean }) {
    return (
        <span className="relative flex grow basis-0 items-center justify-center">
            <span
                className={cn(
                    'h-full w-px md:h-px md:w-full',
                    done ? 'bg-[#0ABFBF]' : 'text-border',
                    !done &&
                        'bg-[repeating-linear-gradient(180deg,currentColor_0_3px,transparent_3px_6px)] md:bg-[repeating-linear-gradient(90deg,currentColor_0_3px,transparent_3px_6px)]',
                )}
            />

            {gated && (
                <span
                    className="absolute top-1/2 left-1/2 flex size-5 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-sidebar-border/70 bg-card text-muted-foreground dark:border-sidebar-border"
                    title="Unlocks when every requirement below is met"
                >
                    <Lock className="size-2.5" />
                    <span className="sr-only">
                        Unlocks when every requirement below is met
                    </span>
                </span>
            )}
        </span>
    );
}

function Marker({ number, position }: { number: number; position: Position }) {
    if (position === 'done') {
        return (
            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#0ABFBF] text-white">
                <Check className="size-3.5" strokeWidth={3} />
            </span>
        );
    }

    return (
        <span
            className={cn(
                'flex size-6 shrink-0 items-center justify-center rounded-full border-2 text-xs font-semibold',
                position === 'current'
                    ? 'border-[#0ABFBF] bg-[#0ABFBF]/10 text-teal-700 dark:text-[#0ABFBF]'
                    : 'border-dashed border-border bg-card text-muted-foreground',
            )}
        >
            {number}
        </span>
    );
}
