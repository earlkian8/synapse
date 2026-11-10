import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import {
    MOOD_VARS,
    MOOD_WORDS,
    formatMean,
    monthName,
    monthShort,
    moodWord,
} from '../constants';
import type { GroupResult, TrendPoint } from '../types';

/**
 * The Check-ins charts (ADR 0102), plain HTML and SVG like the predictive screens'
 * kit. Mood is a diverging scale (blue → neutral → amber, validated for colour-blind
 * readers); each mark keeps a 2px gap from its neighbour, every mark has a tooltip,
 * and a group with fewer than five answers is drawn as an outline that says why it
 * is empty instead of a number.
 */

/** The mood legend, Rough → Great. Always shown beside a mood chart. */
export function MoodLegend({ className }: { className?: string }) {
    return (
        <ul
            className={cn(
                'flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground',
                className,
            )}
        >
            {MOOD_WORDS.map((word, i) => (
                <li key={word} className="flex items-center gap-1.5">
                    <span
                        className="size-2.5 rounded-[3px]"
                        style={{ background: MOOD_VARS[i] }}
                        aria-hidden
                    />
                    {word}
                </li>
            ))}
        </ul>
    );
}

/** How a group's answers split across the five moods, as one bar. */
export function MoodBar({
    group,
    label,
    className,
}: {
    group: GroupResult;
    label: string;
    className?: string;
}) {
    if (!group.shown || !group.moods) {
        return (
            <div
                className={cn(
                    'h-3 rounded-[4px] border border-dashed border-border',
                    className,
                )}
                role="img"
                aria-label={`${label}: fewer than five answers, not shown`}
            />
        );
    }

    const total = group.answers;

    return (
        <div
            className={cn('flex h-3 gap-[2px]', className)}
            role="img"
            aria-label={`${label}: ${describe(group)}`}
        >
            {group.moods.map((count, i) =>
                count === 0 ? null : (
                    <Tooltip key={MOOD_WORDS[i]}>
                        <TooltipTrigger asChild>
                            <span
                                tabIndex={0}
                                className="h-full rounded-[3px] outline-none first:rounded-l-[4px] last:rounded-r-[4px] focus-visible:ring-2 focus-visible:ring-ring"
                                style={{
                                    flexGrow: count,
                                    flexBasis: 0,
                                    background: MOOD_VARS[i],
                                }}
                            />
                        </TooltipTrigger>
                        <TooltipContent side="top">
                            <p className="font-semibold tabular-nums">
                                {MOOD_WORDS[i]}:{' '}
                                {Math.round((count / total) * 100)}%
                            </p>
                            <p className="opacity-80">
                                {count} of {total} answers
                            </p>
                        </TooltipContent>
                    </Tooltip>
                ),
            )}
        </div>
    );
}

/** The year's mood, month by month: a 100% column per month. */
export function MoodTrend({
    points,
    selected,
}: {
    points: TrendPoint[];
    selected: string;
}) {
    return (
        <figure className="flex flex-col gap-3">
            <div className="grid h-44 grid-cols-12 items-end gap-1.5 sm:gap-2.5">
                {points.map((point) => {
                    const isSelected = point.period === selected;

                    return (
                        <Tooltip key={point.period}>
                            <TooltipTrigger asChild>
                                <div
                                    tabIndex={0}
                                    aria-label={`${monthName(point.period, true)}: ${point.shown ? describe(point) : point.answers === 0 ? 'no answers' : 'fewer than five answers, not shown'}`}
                                    className={cn(
                                        'flex h-full flex-col-reverse gap-[2px] rounded-[5px] outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                        !point.shown &&
                                            'border border-dashed border-border',
                                        isSelected &&
                                            'ring-2 ring-foreground/70 ring-offset-2 ring-offset-card',
                                    )}
                                >
                                    {point.shown &&
                                        point.moods?.map((count, i) =>
                                            count === 0 ? null : (
                                                <span
                                                    key={i}
                                                    className="w-full rounded-[3px] first:rounded-b-[4px] last:rounded-t-[4px]"
                                                    style={{
                                                        flexGrow: count,
                                                        flexBasis: 0,
                                                        background:
                                                            MOOD_VARS[i],
                                                    }}
                                                />
                                            ),
                                        )}
                                </div>
                            </TooltipTrigger>
                            <TooltipContent side="top" className="max-w-56">
                                <p className="font-semibold">
                                    {monthName(point.period, true)}
                                </p>
                                {point.shown && point.moods ? (
                                    <ul className="mt-1 flex flex-col gap-0.5 tabular-nums">
                                        {[...point.moods]
                                            .reverse()
                                            .map((count, j) => {
                                                const i = 4 - j;

                                                return (
                                                    <li
                                                        key={i}
                                                        className="flex items-center justify-between gap-4"
                                                    >
                                                        <span className="flex items-center gap-1.5">
                                                            <span
                                                                className="size-2 rounded-[2px]"
                                                                style={{
                                                                    background:
                                                                        MOOD_VARS[
                                                                            i
                                                                        ],
                                                                }}
                                                            />
                                                            {MOOD_WORDS[i]}
                                                        </span>
                                                        <span>
                                                            {Math.round(
                                                                (count /
                                                                    point.answers) *
                                                                    100,
                                                            )}
                                                            %
                                                        </span>
                                                    </li>
                                                );
                                            })}
                                        <li className="mt-1 opacity-80">
                                            {point.answers} answers · average{' '}
                                            {formatMean(point.mood)}
                                        </li>
                                    </ul>
                                ) : (
                                    <p className="opacity-80">
                                        {point.answers === 0
                                            ? 'No one answered.'
                                            : `${point.answers} ${point.answers === 1 ? 'answer' : 'answers'}: fewer than five, so not shown.`}
                                    </p>
                                )}
                            </TooltipContent>
                        </Tooltip>
                    );
                })}
            </div>
            <div className="grid grid-cols-12 gap-1.5 sm:gap-2.5">
                {points.map((point) => (
                    <span
                        key={point.period}
                        className={cn(
                            'text-center text-[11px] text-muted-foreground',
                            point.period === selected &&
                                'font-semibold text-foreground',
                        )}
                    >
                        {monthShort(point.period)}
                    </span>
                ))}
            </div>
            <MoodLegend />
        </figure>
    );
}

/**
 * One measure over the year as a line, on its own fixed scale — the three measures
 * are three small charts, never one chart with two axes. Months not shown break the
 * line rather than bridging it.
 */
export function MeasureTrend({
    title,
    points,
    value,
    domain,
    format,
    ticks,
}: {
    title: string;
    points: TrendPoint[];
    value: (point: TrendPoint) => number | undefined;
    domain: [number, number];
    format: (value: number) => string;
    ticks: number[];
}) {
    // Positions as percentages of the plot: each month at the middle of its column.
    const x = (i: number) => ((i + 0.5) / points.length) * 100;
    const y = (v: number) =>
        (1 - (v - domain[0]) / (domain[1] - domain[0])) * 100;

    // Runs of consecutive shown months, each drawn as its own line.
    const runs: { i: number; v: number }[][] = [[]];
    points.forEach((point, i) => {
        const v = point.shown ? value(point) : undefined;

        if (v === undefined) {
            runs.push([]);
        } else {
            runs[runs.length - 1].push({ i, v });
        }
    });
    const latest = [...points]
        .reverse()
        .find((p) => p.shown && value(p) !== undefined);

    return (
        <figure className="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <figcaption className="flex items-baseline justify-between gap-2">
                <span className="text-sm font-medium">{title}</span>
                <span className="text-sm font-semibold tabular-nums">
                    {latest ? format(value(latest)!) : '—'}
                </span>
            </figcaption>
            <div className="flex gap-2">
                <div className="relative h-24 w-7 shrink-0" aria-hidden>
                    {ticks.map((t) => (
                        <span
                            key={t}
                            className="absolute right-0 -translate-y-1/2 text-[10px] text-muted-foreground tabular-nums"
                            style={{ top: `${y(t)}%` }}
                        >
                            {format(t)}
                        </span>
                    ))}
                </div>
                <div className="relative h-24 min-w-0 flex-1">
                    <svg
                        viewBox="0 0 100 100"
                        preserveAspectRatio="none"
                        className="absolute inset-0 size-full overflow-visible"
                        aria-hidden
                    >
                        {ticks.map((t) => (
                            <line
                                key={t}
                                x1={0}
                                x2={100}
                                y1={y(t)}
                                y2={y(t)}
                                className="stroke-border"
                                strokeWidth={1}
                                vectorEffect="non-scaling-stroke"
                            />
                        ))}
                        {runs
                            .filter((run) => run.length > 1)
                            .map((run) => (
                                <polyline
                                    key={run[0].i}
                                    points={run
                                        .map((p) => `${x(p.i)},${y(p.v)}`)
                                        .join(' ')}
                                    fill="none"
                                    className="stroke-foreground"
                                    strokeWidth={2}
                                    strokeLinejoin="round"
                                    strokeLinecap="round"
                                    vectorEffect="non-scaling-stroke"
                                />
                            ))}
                    </svg>
                    {runs.flat().map((p) => (
                        <span
                            key={p.i}
                            aria-hidden
                            className="pointer-events-none absolute size-2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-foreground ring-2 ring-card"
                            style={{ left: `${x(p.i)}%`, top: `${y(p.v)}%` }}
                        />
                    ))}
                    {/* Hit areas: a column per month, wider than the mark. */}
                    <div className="absolute inset-0 flex">
                        {points.map((point) => {
                            const v = point.shown ? value(point) : undefined;

                            return (
                                <Tooltip key={point.period}>
                                    <TooltipTrigger asChild>
                                        <span
                                            tabIndex={0}
                                            aria-label={`${title}, ${monthName(point.period, true)}: ${v === undefined ? 'not shown' : format(v)}`}
                                            className="h-full flex-1 rounded-sm outline-none hover:bg-foreground/5 focus-visible:bg-foreground/5"
                                        />
                                    </TooltipTrigger>
                                    <TooltipContent side="top">
                                        <p className="font-semibold tabular-nums">
                                            {v === undefined
                                                ? 'Not shown'
                                                : format(v)}
                                        </p>
                                        <p className="opacity-80">
                                            {monthName(point.period, true)}
                                            {v === undefined &&
                                            point.answers > 0
                                                ? ' · fewer than five answers'
                                                : ''}
                                        </p>
                                    </TooltipContent>
                                </Tooltip>
                            );
                        })}
                    </div>
                </div>
            </div>
        </figure>
    );
}

/** A shown group in words, for screen readers. */
function describe(group: GroupResult): string {
    if (!group.moods) {
        return '';
    }

    const parts = group.moods
        .map((count, i) =>
            count > 0
                ? `${MOOD_WORDS[i]} ${Math.round((count / group.answers) * 100)}%`
                : null,
        )
        .filter(Boolean);

    return `${group.answers} answers, average ${formatMean(group.mood)} (${moodWord(group.mood ?? 3).toLowerCase()}): ${parts.join(', ')}`;
}
