import { Check, Lock } from 'lucide-react';
import { cn } from '@/lib/utils';
import { STAGE_LABELS, STAGE_ORDER } from '../constants';
import type { Stage } from '../types';

type Position = 'done' | 'current' | 'locked';

function positionFor(stage: Stage, active: Stage): Position {
    const index = STAGE_ORDER.indexOf(stage);
    const activeIndex = STAGE_ORDER.indexOf(active);

    if (index < activeIndex) {
        return 'done';
    }

    return index === activeIndex ? 'current' : 'locked';
}

/**
 * The lifecycle a model moves through, as an ordered rail. Order carries real
 * information here — a model cannot graduate before it has collected — so this
 * is a genuine sequence rather than decorative numbering.
 *
 * The gate on the connector into the final stage is drawn closed whenever the
 * model has not graduated, because that closure is the point of the surface.
 */
export function StageRail({
    stage,
    summaries,
}: {
    stage: Stage;
    summaries: Record<Stage, string>;
}) {
    const lastIndex = STAGE_ORDER.length - 1;

    return (
        <div>
            <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                Lifecycle
            </span>

            <ol className="mt-3 flex flex-col md:grid md:grid-cols-3">
                {STAGE_ORDER.map((item, index) => (
                    <StageNode
                        key={item}
                        stage={item}
                        summary={summaries[item]}
                        position={positionFor(item, stage)}
                        isLast={index === lastIndex}
                        /* The connector out of the second-to-last stage is the gate. */
                        gated={index === lastIndex - 1 && stage !== 'graduated'}
                    />
                ))}
            </ol>
        </div>
    );
}

function StageNode({
    stage,
    summary,
    position,
    isLast,
    gated,
}: {
    stage: Stage;
    summary: string;
    position: Position;
    isLast: boolean;
    gated: boolean;
}) {
    return (
        <li className="flex gap-3 md:flex-col md:gap-0">
            {/* Rail: runs downward on mobile, across from md up. */}
            <div className="flex w-5 shrink-0 flex-col items-center md:h-5 md:w-full md:flex-row">
                <Marker position={position} />
                {!isLast && (
                    <Connector
                        faded={position !== 'done'}
                        dashed={gated}
                        gated={gated}
                    />
                )}
            </div>

            <div className="min-w-0 pb-6 md:mt-3 md:pr-8 md:pb-0">
                <p
                    className={cn(
                        'flex flex-wrap items-center gap-2 text-sm font-medium',
                        position === 'locked' && 'text-muted-foreground',
                    )}
                >
                    {STAGE_LABELS[stage]}
                    {position === 'current' && (
                        <span className="rounded-full border border-[#0ABFBF]/30 bg-[#0ABFBF]/10 px-2 py-0.5 text-[10px] font-medium tracking-wide text-[#0ABFBF] uppercase">
                            Now
                        </span>
                    )}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">{summary}</p>
            </div>
        </li>
    );
}

/** The line between two markers, optionally carrying the closed retraining gate. */
function Connector({
    faded,
    dashed,
    gated,
}: {
    faded: boolean;
    dashed: boolean;
    gated: boolean;
}) {
    return (
        <span className="relative flex grow basis-0 items-center justify-center">
            <span
                className={cn(
                    'h-full w-px md:h-px md:w-full',
                    faded ? 'text-border' : 'text-[#0ABFBF]',
                    dashed
                        ? 'bg-[repeating-linear-gradient(180deg,currentColor_0_3px,transparent_3px_6px)] md:bg-[repeating-linear-gradient(90deg,currentColor_0_3px,transparent_3px_6px)]'
                        : 'bg-current',
                )}
            />

            {gated && (
                <span
                    className="absolute top-1/2 left-1/2 flex size-5 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-sidebar-border/70 bg-card text-muted-foreground dark:border-sidebar-border"
                    title="Retraining gate — closed"
                >
                    <Lock className="size-2.5" />
                </span>
            )}
        </span>
    );
}

function Marker({ position }: { position: Position }) {
    if (position === 'done') {
        return (
            <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-[#0ABFBF] text-white">
                <Check className="size-3" strokeWidth={3} />
            </span>
        );
    }

    if (position === 'current') {
        return (
            <span className="flex size-5 shrink-0 items-center justify-center rounded-full border-2 border-[#0ABFBF] bg-card">
                <span className="size-2 rounded-full bg-[#0ABFBF]" />
            </span>
        );
    }

    return (
        <span className="size-5 shrink-0 rounded-full border-2 border-dashed border-border bg-card" />
    );
}
