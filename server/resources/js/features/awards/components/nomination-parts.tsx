import { Medal } from 'lucide-react';
import { cn } from '@/lib/utils';
import { SIGNAL_COLORS } from '../constants';
import type { Nominee } from '../types';

/**
 * A nominee's fit score as a bar segmented by signal (out of the profile's full
 * 100), each segment in the signal's fixed hue — compact enough for a table cell.
 */
export function ContributionBar({
    nominee,
    className,
}: {
    nominee: Nominee;
    className?: string;
}) {
    const available = nominee.components.reduce((sum, c) => sum + c.max, 0);

    return (
        <div
            className={cn(
                'flex h-1.5 w-full gap-px overflow-hidden rounded-full bg-muted',
                className,
            )}
            role="img"
            aria-label={`Fit score ${nominee.score} out of 100: ${nominee.components
                .map((c) => `${c.label} ${c.points} of ${c.max}`)
                .join(', ')}`}
        >
            {nominee.components.map((component) =>
                component.points > 0 ? (
                    <div
                        key={component.key}
                        className="h-full"
                        title={`${component.label}: ${component.points}/${component.max}`}
                        style={{
                            width: `${(component.points / available) * 100}%`,
                            backgroundColor: SIGNAL_COLORS[component.key],
                        }}
                    />
                ) : null,
            )}
        </div>
    );
}

/** #1 gold, #2 silver, #3 bronze; the rest plain. */
export function RankBadge({ rank }: { rank: number }) {
    const styles: Record<number, string> = {
        1: 'border-amber-400/40 bg-amber-400/15 text-amber-600 dark:text-amber-400',
        2: 'border-slate-400/40 bg-slate-400/15 text-slate-600 dark:text-slate-300',
        3: 'border-orange-600/30 bg-orange-600/10 text-orange-700 dark:text-orange-400',
    };

    return (
        <span
            className={cn(
                'flex size-6 shrink-0 items-center justify-center rounded-full border text-[11px] font-bold tabular-nums',
                styles[rank] ?? 'border-border bg-muted text-muted-foreground',
            )}
            aria-label={`Rank ${rank}`}
        >
            {rank === 1 ? <Medal className="size-3.5" /> : rank}
        </span>
    );
}

/**
 * The transparent "why" behind a score: each signal's grounded detail and the
 * points it earned of what it could — the bar's segments, spelled out.
 */
export function Breakdown({ nominee }: { nominee: Nominee }) {
    return (
        <ul className="grid gap-x-6 gap-y-1 sm:grid-cols-2">
            {nominee.components.map((component) => (
                <li
                    key={component.key}
                    className="flex items-center gap-2 text-xs"
                >
                    <span
                        className="size-2 shrink-0 rounded-full"
                        style={{
                            backgroundColor: SIGNAL_COLORS[component.key],
                        }}
                        aria-hidden="true"
                    />
                    <span className="w-24 shrink-0 font-medium">
                        {component.label}
                    </span>
                    <span className="min-w-0 flex-1 truncate text-muted-foreground">
                        {component.detail}
                    </span>
                    <span className="shrink-0 text-muted-foreground tabular-nums">
                        {component.points}/{component.max}
                    </span>
                </li>
            ))}
        </ul>
    );
}
