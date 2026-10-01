import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import { useMemo } from 'react';
import { cn } from '@/lib/utils';
import type { RiskFactor } from '../types';

/**
 * What moved a risk score: each input's signed contribution, as a bar normalised
 * to the strongest one shown. The palette is inverted versus Promotion Readiness —
 * a factor pushing risk up is the bad direction here, so it is rose, and one
 * pulling it down is emerald.
 */
export function FactorList({
    factors,
    className,
}: {
    factors: RiskFactor[];
    className?: string;
}) {
    const max = useMemo(
        () => Math.max(1e-6, ...factors.map((f) => Math.abs(f.impact))),
        [factors],
    );

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
                                    <ArrowUpRight className="size-3.5 shrink-0 text-rose-500" />
                                ) : (
                                    <ArrowDownRight className="size-3.5 shrink-0 text-emerald-500" />
                                )}
                                <span className="truncate">{factor.label}</span>
                            </span>
                            <span
                                className={cn(
                                    'shrink-0 text-xs font-medium',
                                    up
                                        ? 'text-rose-600 dark:text-rose-400'
                                        : 'text-emerald-600 dark:text-emerald-400',
                                )}
                            >
                                {up ? 'Raises risk' : 'Lowers risk'}
                            </span>
                        </div>
                        <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                className={cn(
                                    'h-full rounded-full',
                                    up ? 'bg-rose-500' : 'bg-emerald-500',
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
