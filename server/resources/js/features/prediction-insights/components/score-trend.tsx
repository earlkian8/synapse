import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { shortDate } from '../lib';
import type { HistoryPoint } from '../types';

const W = 560;
const H = 190;
const PAD = { top: 14, right: 18, bottom: 26, left: 32 };
const IW = W - PAD.left - PAD.right;
const IH = H - PAD.top - PAD.bottom;

/**
 * One person's score across the runs they were in, oldest first, on the same
 * 0–100 scale as the page, with hairlines where the tiers begin. A forecast also
 * draws each run's likely range and, once the period was appraised, what it
 * actually gave — so its track record for this person is plain to see. Each point
 * has a tooltip on hover and keyboard focus.
 */
export function ScoreTrend({
    points,
    guides,
    tierFill,
    tierLabels,
    noun,
    forecast = false,
}: {
    points: HistoryPoint[];
    guides: { value: number; label: string }[];
    /** SVG fill class for a point's tier or band. */
    tierFill: (tier: string) => string;
    tierLabels: Record<string, string>;
    noun: string;
    forecast?: boolean;
}) {
    if (points.length < 2) {
        return (
            <p className="rounded-xl border border-dashed border-sidebar-border/70 px-4 py-5 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                {points.length === 0
                    ? 'No earlier runs include this person.'
                    : 'This is the first run that includes this person. The trend starts with the next one.'}
            </p>
        );
    }

    const step = IW / (points.length - 1);
    const x = (i: number) => PAD.left + i * step;
    const y = (v: number) =>
        PAD.top + IH - (Math.max(0, Math.min(100, v)) / 100) * IH;
    const path = points
        .map(
            (p, i) =>
                `${i === 0 ? 'M' : 'L'} ${x(i).toFixed(1)} ${y(p.score).toFixed(1)}`,
        )
        .join(' ');
    const actuals = points.filter((p) => p.actual != null).length;
    // Thin out date labels so they never collide.
    const every = Math.ceil(points.length / 6);

    return (
        <figure className="flex flex-col gap-1.5">
            <svg
                viewBox={`0 0 ${W} ${H}`}
                className="h-auto w-full"
                role="group"
                aria-label={`${noun} in each run, oldest first`}
            >
                {[0, 50, 100].map((v) => (
                    <g key={v}>
                        <line
                            x1={PAD.left}
                            x2={W - PAD.right}
                            y1={y(v)}
                            y2={y(v)}
                            className="stroke-border"
                            strokeWidth={1}
                        />
                        <text
                            x={PAD.left - 6}
                            y={y(v) + 3}
                            textAnchor="end"
                            className="fill-muted-foreground text-[10px] tabular-nums"
                        >
                            {v}
                        </text>
                    </g>
                ))}
                {guides.map((g) => (
                    <g key={g.label}>
                        <line
                            x1={PAD.left}
                            x2={W - PAD.right}
                            y1={y(g.value)}
                            y2={y(g.value)}
                            className="stroke-foreground/25"
                            strokeDasharray="3 3"
                            strokeWidth={1}
                        />
                        <text
                            x={PAD.left + 4}
                            y={y(g.value) - 4}
                            className="fill-muted-foreground text-[10px]"
                        >
                            {g.label} from {Math.round(g.value)}
                        </text>
                    </g>
                ))}

                {forecast &&
                    points.map((p, i) =>
                        p.low != null && p.high != null ? (
                            <line
                                key={`range-${p.run}`}
                                x1={x(i)}
                                x2={x(i)}
                                y1={y(p.high)}
                                y2={y(p.low)}
                                className="stroke-teal-600/30"
                                strokeWidth={8}
                                strokeLinecap="round"
                            />
                        ) : null,
                    )}

                <path
                    d={path}
                    fill="none"
                    className="stroke-foreground/55"
                    strokeWidth={2}
                    strokeLinejoin="round"
                    strokeLinecap="round"
                />

                {points.map((p, i) => {
                    const last = i === points.length - 1;

                    return (
                        <Tooltip key={p.run}>
                            <TooltipTrigger asChild>
                                <g
                                    tabIndex={0}
                                    aria-label={`${shortDate(p.at)}: ${noun} ${Math.round(p.score)}, ${tierLabels[p.tier] ?? p.tier}${p.actual != null ? `; actual ${p.actual.toFixed(1)}` : ''}`}
                                    className="outline-none [&:focus-visible>circle.mark]:stroke-ring"
                                >
                                    <circle
                                        cx={x(i)}
                                        cy={y(p.score)}
                                        r={13}
                                        fill="transparent"
                                    />
                                    <circle
                                        cx={x(i)}
                                        cy={y(p.score)}
                                        r={last ? 5.5 : 4.5}
                                        className={cn(
                                            'mark stroke-card',
                                            tierFill(p.tier),
                                        )}
                                        strokeWidth={2}
                                    />
                                    {p.actual != null && (
                                        <rect
                                            x={x(i) - 4.5}
                                            y={y(p.actual) - 4.5}
                                            width={9}
                                            height={9}
                                            transform={`rotate(45 ${x(i)} ${y(p.actual)})`}
                                            className="fill-card stroke-foreground"
                                            strokeWidth={2}
                                        />
                                    )}
                                </g>
                            </TooltipTrigger>
                            <TooltipContent side="top">
                                <p className="font-semibold tabular-nums">
                                    {Math.round(p.score)} ·{' '}
                                    {tierLabels[p.tier] ?? p.tier}
                                </p>
                                <p className="opacity-80">
                                    {p.period ? `${p.period} · ` : ''}run of{' '}
                                    {shortDate(p.at)}
                                </p>
                                {p.low != null && p.high != null && (
                                    <p className="opacity-80">
                                        Likely {Math.round(p.low)}–
                                        {Math.round(p.high)}
                                    </p>
                                )}
                                {p.actual != null && (
                                    <p className="opacity-80">
                                        Actual {p.actual.toFixed(1)} (
                                        {p.actual - p.score >= 0 ? '+' : '−'}
                                        {Math.abs(p.actual - p.score).toFixed(
                                            1,
                                        )}
                                        )
                                    </p>
                                )}
                            </TooltipContent>
                        </Tooltip>
                    );
                })}

                {points.map((p, i) =>
                    i % every === 0 || i === points.length - 1 ? (
                        <text
                            key={`date-${p.run}`}
                            x={x(i)}
                            y={H - 8}
                            textAnchor={
                                i === 0
                                    ? 'start'
                                    : i === points.length - 1
                                      ? 'end'
                                      : 'middle'
                            }
                            className="fill-muted-foreground text-[10px] tabular-nums"
                        >
                            {shortDate(p.at)}
                        </text>
                    ) : null,
                )}
            </svg>
            {forecast && (
                <figcaption className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-muted-foreground">
                    <span className="flex items-center gap-1.5">
                        <span className="size-2.5 rounded-full bg-foreground/55" />
                        Forecast
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="h-3 w-2 rounded-full bg-teal-600/30" />
                        Likely range
                    </span>
                    {actuals > 0 && (
                        <span className="flex items-center gap-1.5">
                            <span className="size-2 rotate-45 border-2 border-foreground bg-card" />
                            What the period gave
                        </span>
                    )}
                </figcaption>
            )}
        </figure>
    );
}
