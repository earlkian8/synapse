import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import { formatWhen } from '../lib';
import { SURFACE_ORDER, SURFACES } from '../surfaces';
import type { Signal, Surface } from '../types';
import { personHref } from '../use-prediction-context';

/**
 * The person's latest result on the other predictive screens, beside this one — a
 * risk is read differently for someone who is also promotion-ready, and a forecast
 * differently for someone likely to leave. Each opens the person on that screen.
 * Screens the viewer may not open are not sent, so they are simply not shown.
 */
export function OtherPredictions({
    signals,
    employeeId,
}: {
    signals: Partial<Record<Surface, Signal | null>>;
    employeeId: number;
}) {
    const shown = SURFACE_ORDER.filter((s) => s in signals);

    if (shown.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-2 sm:grid-cols-2">
            {shown.map((surface) => {
                const signal = signals[surface] ?? null;
                const meta = SURFACES[surface];

                if (signal === null) {
                    return (
                        <div
                            key={surface}
                            className="flex flex-col gap-1 rounded-xl border border-dashed border-sidebar-border/70 px-3.5 py-3 dark:border-sidebar-border"
                        >
                            <span className="text-xs text-muted-foreground">
                                {meta.name}
                            </span>
                            <span className="text-sm text-muted-foreground">
                                Not assessed yet
                            </span>
                        </div>
                    );
                }

                return (
                    <Link
                        key={surface}
                        href={personHref(surface, employeeId)}
                        className="group flex flex-col gap-1.5 rounded-xl border border-sidebar-border/70 px-3.5 py-3 transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:border-sidebar-border"
                    >
                        <span className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                            {meta.name}
                            <ArrowUpRight className="size-3.5 opacity-60 transition-opacity group-hover:opacity-100" />
                        </span>
                        <span className="flex items-center gap-2">
                            <span className="text-xl leading-none font-semibold tracking-tight tabular-nums">
                                {Math.round(signal.score)}
                            </span>
                            <span
                                className={cn(
                                    'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                    meta.styles[signal.tier],
                                )}
                            >
                                {meta.labels[signal.tier] ?? signal.tier}
                            </span>
                        </span>
                        <span className="text-[11px] text-muted-foreground">
                            {signal.low != null && signal.high != null
                                ? `Likely ${Math.round(signal.low)}–${Math.round(signal.high)} · `
                                : ''}
                            {formatWhen(signal.at)}
                        </span>
                    </Link>
                );
            })}
        </div>
    );
}
