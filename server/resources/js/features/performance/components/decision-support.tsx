import {
    Brain,
    Minus,
    TrendingDown,
    TrendingUp,
    TriangleAlert,
    Trophy,
} from 'lucide-react';
import { useMemo } from 'react';
import { cn } from '@/lib/utils';
import { formatLineScore, formatScore, scaleFraction } from '../constants';
import type {
    DecisionSupport as DecisionSupportData,
    ForecastBand,
    PerformanceScore,
} from '../types';

const BAND_META: Record<
    ForecastBand,
    { label: string; tone: string; bar: string }
> = {
    below: {
        label: 'Below track',
        tone: 'text-rose-600 dark:text-rose-400',
        bar: 'bg-rose-500',
    },
    on_track: {
        label: 'On track',
        tone: 'text-sky-600 dark:text-sky-400',
        bar: 'bg-sky-500',
    },
    exceeds: {
        label: 'Exceeds',
        tone: 'text-emerald-600 dark:text-emerald-400',
        bar: 'bg-emerald-500',
    },
};

type Props = {
    support: DecisionSupportData;
    scores: PerformanceScore[];
};

/**
 * Per-employee decision support: the ML performance forecast for the next
 * period, the employee's overall-score trajectory, and the standout strengths /
 * focus areas derived from this scorecard. Sits alongside the AI coaching panel.
 */
export function DecisionSupport({ support, scores }: Props) {
    const { history, forecast } = support;

    const trend = useMemo(() => {
        const scored = history.filter((h) => h.score !== null);

        if (scored.length < 2) {
            return null;
        }

        const delta =
            scored[scored.length - 1].score - scored[scored.length - 2].score;

        return {
            delta,
            direction: delta > 0.05 ? 'up' : delta < -0.05 ? 'down' : 'steady',
        } as const;
    }, [history]);

    const { strengths, focus } = useMemo(() => {
        const ranked = scores
            .filter((s) => s.score !== null)
            .map((s) => ({ line: s, fraction: scaleFraction(s) ?? 0 }))
            .sort((a, b) => b.fraction - a.fraction);

        return {
            strengths: ranked.slice(0, 2).filter((r) => r.fraction >= 0.5),
            focus: ranked
                .slice(-2)
                .reverse()
                .filter((r) => r.fraction < 0.75),
        };
    }, [scores]);

    const maxScore = 5;

    return (
        <section className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
            <header className="flex items-center gap-2 border-b border-border px-4 py-2.5">
                <span className="flex size-6 items-center justify-center rounded-md bg-violet-500/10 text-violet-600 dark:text-violet-400">
                    <Brain className="size-3.5" />
                </span>
                <h3 className="text-sm font-semibold tracking-tight">
                    Decision support
                </h3>
                <span className="text-xs text-muted-foreground">
                    · ML forecast &amp; trajectory
                </span>
            </header>

            <div className="grid gap-4 p-4 lg:grid-cols-2">
                {/* Trajectory */}
                <div className="rounded-lg border border-border/60 bg-muted/20 p-3.5">
                    <div className="mb-3 flex items-center justify-between">
                        <span className="text-xs font-medium text-muted-foreground">
                            Overall-score trajectory
                        </span>
                        {trend && (
                            <span
                                className={cn(
                                    'inline-flex items-center gap-1 text-xs font-medium tabular-nums',
                                    trend.direction === 'up' &&
                                        'text-emerald-600 dark:text-emerald-400',
                                    trend.direction === 'down' &&
                                        'text-rose-600 dark:text-rose-400',
                                    trend.direction === 'steady' &&
                                        'text-muted-foreground',
                                )}
                            >
                                {trend.direction === 'up' && (
                                    <TrendingUp className="size-3.5" />
                                )}
                                {trend.direction === 'down' && (
                                    <TrendingDown className="size-3.5" />
                                )}
                                {trend.direction === 'steady' && (
                                    <Minus className="size-3.5" />
                                )}
                                {trend.delta > 0 ? '+' : ''}
                                {trend.delta.toFixed(2)}
                            </span>
                        )}
                    </div>

                    {history.length === 0 ? (
                        <p className="text-xs text-muted-foreground">
                            No scored history yet.
                        </p>
                    ) : (
                        <div className="flex h-24 items-end gap-1.5">
                            {history.map((point, index) => (
                                <div
                                    key={index}
                                    className="flex min-w-0 flex-1 flex-col items-center gap-1"
                                    title={`${point.period ?? 'Period'}: ${formatScore(point.score)}`}
                                >
                                    <span className="text-[10px] text-muted-foreground tabular-nums">
                                        {point.score.toFixed(1)}
                                    </span>
                                    <div
                                        className={cn(
                                            'w-full rounded-t-sm transition-all',
                                            point.is_current
                                                ? 'bg-[#0ABFBF]'
                                                : 'bg-[#0ABFBF]/35',
                                        )}
                                        style={{
                                            height: `${Math.max(
                                                6,
                                                (point.score / maxScore) * 100,
                                            )}%`,
                                        }}
                                    />
                                    <span className="w-full truncate text-center text-[9px] text-muted-foreground">
                                        {point.period ?? '—'}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* ML forecast */}
                <div className="rounded-lg border border-border/60 bg-muted/20 p-3.5">
                    <span className="text-xs font-medium text-muted-foreground">
                        Next-period forecast
                    </span>
                    {forecast ? (
                        <ForecastBody forecast={forecast} />
                    ) : (
                        <p className="mt-3 text-xs leading-relaxed text-muted-foreground">
                            No ML forecast yet. Run a{' '}
                            <span className="font-medium text-foreground">
                                Performance Forecast
                            </span>{' '}
                            in Analytics to project this employee's next-period
                            rating.
                        </p>
                    )}
                </div>
            </div>

            {(strengths.length > 0 || focus.length > 0) && (
                <div className="grid gap-4 border-t border-border px-4 py-3 sm:grid-cols-2">
                    {strengths.length > 0 && (
                        <SignalList
                            label="Standout strengths"
                            icon={
                                <Trophy className="size-3.5 text-emerald-500" />
                            }
                            items={strengths.map((s) => ({
                                label: s.line.label,
                                value: formatLineScore(s.line),
                            }))}
                        />
                    )}
                    {focus.length > 0 && (
                        <SignalList
                            label="Focus areas"
                            icon={
                                <TriangleAlert className="size-3.5 text-amber-500" />
                            }
                            items={focus.map((s) => ({
                                label: s.line.label,
                                value: formatLineScore(s.line),
                            }))}
                        />
                    )}
                </div>
            )}
        </section>
    );
}

function ForecastBody({
    forecast,
}: {
    forecast: NonNullable<DecisionSupportData['forecast']>;
}) {
    const meta = BAND_META[forecast.band];

    return (
        <div className="mt-2">
            <div className="flex items-baseline gap-2">
                <span
                    className={cn(
                        'text-2xl font-semibold tracking-tight tabular-nums',
                        meta.tone,
                    )}
                >
                    {Math.round(forecast.predicted_rating)}
                </span>
                <span className="text-xs text-muted-foreground">/ 100</span>
                <span className={cn('ml-auto text-sm font-medium', meta.tone)}>
                    {meta.label}
                </span>
            </div>
            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    className={cn('h-full rounded-full', meta.bar)}
                    style={{ width: `${forecast.predicted_rating}%` }}
                />
            </div>
            <p className="mt-2 text-[11px] text-muted-foreground">
                {Math.round(forecast.confidence * 100)}% confidence · predicted
                by the performance model
            </p>
        </div>
    );
}

function SignalList({
    label,
    icon,
    items,
}: {
    label: string;
    icon: React.ReactNode;
    items: { label: string; value: string }[];
}) {
    return (
        <div>
            <p className="mb-1.5 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {icon}
                {label}
            </p>
            <ul className="flex flex-col gap-1">
                {items.map((item, index) => (
                    <li
                        key={index}
                        className="flex items-center justify-between gap-2 text-sm"
                    >
                        <span className="min-w-0 truncate text-foreground/90">
                            {item.label}
                        </span>
                        <span className="shrink-0 font-medium text-muted-foreground tabular-nums">
                            {item.value}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
