import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { ChartGroup, GroupRow } from '../lib';

/**
 * A run by department: each department's people split by tier or band (a 100%
 * stacked bar, with a surface gap between segments), its head count, and its
 * average score — sorted by that average, so the departments to look at first are
 * on top.
 */
export function GroupBreakdown<K extends string>({
    rows,
    groups,
    averageLabel,
    formatAverage = (v) => Math.round(v).toString(),
}: {
    rows: GroupRow<K>[];
    groups: readonly ChartGroup<K>[];
    averageLabel: string;
    formatAverage?: (value: number) => string;
}) {
    return (
        <div className="flex flex-col gap-1">
            <div className="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)_4.5rem] gap-x-3 text-[11px] text-muted-foreground">
                <span>Department</span>
                <span>Share of its people</span>
                <span className="text-right">{averageLabel}</span>
            </div>
            <ul className="flex flex-col">
                {rows.map((row) => (
                    <li
                        key={row.key}
                        className="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)_4.5rem] items-center gap-x-3 py-1.5"
                    >
                        <span className="flex min-w-0 items-baseline gap-1.5">
                            <span className="truncate text-xs">
                                {row.label}
                            </span>
                            <span className="shrink-0 text-[11px] text-muted-foreground tabular-nums">
                                {row.count}
                            </span>
                        </span>
                        <div className="flex h-3.5 gap-[2px]">
                            {groups.map((g) => {
                                const count = row.segments[g.key];

                                if (count === 0) {
                                    return null;
                                }

                                return (
                                    <Tooltip key={g.key}>
                                        <TooltipTrigger asChild>
                                            <div
                                                tabIndex={0}
                                                aria-label={`${row.label}: ${g.label} ${count} of ${row.count}`}
                                                className={cn(
                                                    'h-full outline-none first:rounded-l-[4px] last:rounded-r-[4px] hover:brightness-110 focus-visible:ring-2 focus-visible:ring-ring',
                                                    g.fill,
                                                )}
                                                style={{ flexGrow: count }}
                                            />
                                        </TooltipTrigger>
                                        <TooltipContent side="top">
                                            <p className="font-semibold tabular-nums">
                                                {count} of {row.count}
                                            </p>
                                            <p className="opacity-80">
                                                {row.label} · {g.label}
                                            </p>
                                        </TooltipContent>
                                    </Tooltip>
                                );
                            })}
                        </div>
                        <span className="text-right text-xs font-medium tabular-nums">
                            {formatAverage(row.average)}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
