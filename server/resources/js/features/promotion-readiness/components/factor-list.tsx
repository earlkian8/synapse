import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import { useMemo } from 'react';
import { cn } from '@/lib/utils';
import type { ReadinessFactor } from '../types';

/**
 * The factors behind a readiness score: each feature's signed contribution,
 * rendered as a left/right diverging bar (green pushes toward promotion, red
 * pulls away). Bar widths are normalised to the strongest factor shown.
 */
export function FactorList({
    factors,
    className,
}: {
    factors: ReadinessFactor[];
    className?: string;
}) {
    const max = useMemo(
        () => Math.max(1e-6, ...factors.map((f) => Math.abs(f.impact))),
        [factors],
    );

    if (factors.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No distinguishing factors for this employee.
            </p>
        );
    }

    return (
        <ul className={cn('flex flex-col gap-2.5', className)}>
            {factors.map((factor) => {
                const up = factor.direction === 'up';
                const width = `${Math.max(6, (Math.abs(factor.impact) / max) * 100)}%`;

                return (
                    <li key={factor.feature} className="flex flex-col gap-1">
                        <div className="flex items-center justify-between gap-2 text-sm">
                            <span className="flex items-center gap-1.5 truncate">
                                {up ? (
                                    <ArrowUpRight className="size-3.5 shrink-0 text-emerald-500" />
                                ) : (
                                    <ArrowDownRight className="size-3.5 shrink-0 text-rose-500" />
                                )}
                                <span className="truncate">{factor.label}</span>
                            </span>
                            <span
                                className={cn(
                                    'shrink-0 text-xs font-medium tabular-nums',
                                    up
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-rose-600 dark:text-rose-400',
                                )}
                            >
                                {up ? '+' : '−'}
                                {Math.abs(factor.impact).toFixed(2)}
                            </span>
                        </div>
                        <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                className={cn(
                                    'h-full rounded-full',
                                    up ? 'bg-emerald-500' : 'bg-rose-500',
                                )}
                                style={{ width }}
                            />
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
