import { useMemo } from 'react';
import type { CSSProperties } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { people } from '../lib';
import type { ChartGroup } from '../lib';

type Item<K extends string> = { score: number; group: K };

/**
 * How scores (0–100) spread across a workforce: one column per `binWidth` points,
 * stacked by group (tier or band), with hairlines where the groups begin. Optionally
 * marks one value — "this employee" — in which case the columns step back to grey
 * so the marker carries the chart (the compact form used in a person's breakdown).
 * HTML rather than SVG, so it stays crisp at any width; each column has a tooltip
 * on hover and keyboard focus.
 */
export function ScoreHistogram<K extends string>({
    items,
    groups,
    thresholds = [],
    marker,
    binWidth = 5,
    height = 128,
    label,
    unit = 'people',
}: {
    items: Item<K>[];
    groups: readonly ChartGroup<K>[];
    /** Where each group begins on the scale, labelled. */
    thresholds?: { value: number; label: string }[];
    /** One value to point at (switches the chart to its compact, emphasis form). */
    marker?: { value: number; label: string };
    binWidth?: number;
    height?: number;
    /** What the chart shows, for screen readers. */
    label: string;
    unit?: string;
}) {
    const bins = useMemo(() => {
        const count = Math.ceil(100 / binWidth);

        return Array.from({ length: count }, (_, i) => {
            const from = i * binWidth;
            const to = from + binWidth;
            const inBin = items.filter(
                (item) =>
                    item.score >= from &&
                    (item.score < to || (i === count - 1 && item.score <= 100)),
            );

            return {
                from,
                to,
                total: inBin.length,
                byGroup: groups.map((g) => ({
                    group: g,
                    count: inBin.filter((item) => item.group === g.key).length,
                })),
            };
        });
    }, [items, groups, binWidth]);

    const max = Math.max(1, ...bins.map((b) => b.total));
    const compact = marker !== undefined;
    const counted = (n: number) =>
        unit === 'people' ? people(n) : `${n} ${unit}`;

    return (
        <figure className="flex flex-col gap-1" aria-label={label}>
            {/* The tallest column's count, above the recessive top gridline. */}
            {!compact && (
                <span className="text-[10px] text-muted-foreground tabular-nums">
                    {counted(max)}
                </span>
            )}
            <div
                className={cn('relative', !compact && 'border-t border-border')}
                style={{ height }}
            >
                <div
                    className="absolute inset-0 grid grid-cols-[repeat(var(--bins),minmax(0,1fr))] items-end border-b border-border"
                    style={{ '--bins': bins.length } as CSSProperties}
                >
                    {bins.map((bin) => (
                        <Tooltip key={bin.from}>
                            <TooltipTrigger asChild>
                                <div
                                    tabIndex={
                                        bin.total > 0 && !compact ? 0 : -1
                                    }
                                    aria-label={`${bin.from}–${bin.to}: ${counted(bin.total)}`}
                                    className="group flex h-full items-end justify-center px-px outline-none focus-visible:bg-muted/60"
                                >
                                    <div
                                        className="flex w-full max-w-6 flex-col-reverse gap-[2px]"
                                        style={{
                                            height: `${(bin.total / max) * 100}%`,
                                        }}
                                    >
                                        {bin.byGroup.map(({ group, count }) =>
                                            count > 0 ? (
                                                <div
                                                    key={group.key}
                                                    className={cn(
                                                        'w-full group-hover:brightness-110 last:rounded-t-[4px]',
                                                        compact
                                                            ? 'bg-muted-foreground/25'
                                                            : group.fill,
                                                    )}
                                                    style={{ flexGrow: count }}
                                                />
                                            ) : null,
                                        )}
                                    </div>
                                </div>
                            </TooltipTrigger>
                            {bin.total > 0 && (
                                <TooltipContent side="top">
                                    <p className="font-semibold tabular-nums">
                                        {counted(bin.total)}
                                    </p>
                                    <p className="opacity-80">
                                        Scored {bin.from}–
                                        {Math.min(100, bin.to)}
                                    </p>
                                    {!compact &&
                                        bin.byGroup
                                            .filter((g) => g.count > 0)
                                            .map(({ group, count }) => (
                                                <p
                                                    key={group.key}
                                                    className="flex items-center gap-1.5 opacity-80"
                                                >
                                                    <span
                                                        className={cn(
                                                            'h-0.5 w-2.5 rounded-full',
                                                            group.fill,
                                                        )}
                                                    />
                                                    {group.label}: {count}
                                                </p>
                                            ))}
                                </TooltipContent>
                            )}
                        </Tooltip>
                    ))}
                </div>

                {/* Where each group begins. */}
                {thresholds.map((t) => (
                    <div
                        key={t.label}
                        className="pointer-events-none absolute inset-y-0 border-l border-foreground/25"
                        style={{ left: `${t.value}%` }}
                    >
                        {!compact && (
                            <span className="absolute -top-4 left-1 text-[10px] whitespace-nowrap text-muted-foreground">
                                {t.label}
                            </span>
                        )}
                    </div>
                ))}

                {marker && (
                    <div
                        className="pointer-events-none absolute inset-y-0 border-l-2 border-[#0ABFBF]"
                        style={{ left: `${Math.min(99.5, marker.value)}%` }}
                    >
                        <span className="absolute -top-0.5 size-2 -translate-x-[5px] rounded-full bg-[#0ABFBF] ring-2 ring-card" />
                        <span className="sr-only">{marker.label}</span>
                    </div>
                )}
            </div>

            <div className="relative h-3.5 text-[10px] text-muted-foreground tabular-nums">
                {[0, 25, 50, 75, 100].map((tick) => (
                    <span
                        key={tick}
                        className={cn(
                            'absolute',
                            tick === 0
                                ? 'left-0'
                                : tick === 100
                                  ? 'right-0'
                                  : '-translate-x-1/2',
                        )}
                        style={
                            tick > 0 && tick < 100
                                ? { left: `${tick}%` }
                                : undefined
                        }
                    >
                        {tick}
                    </span>
                ))}
            </div>
        </figure>
    );
}
