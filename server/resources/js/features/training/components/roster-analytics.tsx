import { CircleCheckBig, TrendingUp, TriangleAlert, UserX } from 'lucide-react';
import type { ComponentType } from 'react';
import { scoreTone } from '../constants';
import type { TrainingAnalytics } from '../types';

/**
 * The program's effectiveness at a glance: a completion-rate meter plus tiles for
 * the average score, the at-risk headcount and dropouts. Derived server-side from
 * the roster.
 */
export function RosterAnalytics({
    analytics,
}: {
    analytics: TrainingAnalytics;
}) {
    const rate = analytics.completion_rate ?? 0;

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1.3fr_2fr]">
            {/* Completion meter */}
            <div className="flex flex-col justify-between gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                <div className="flex items-center justify-between">
                    <span className="flex items-center gap-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        <CircleCheckBig className="size-4 text-[#0ABFBF]" />
                        Completion rate
                    </span>
                    <span className="text-2xl font-semibold tracking-tight tabular-nums">
                        {analytics.completion_rate === null
                            ? '—'
                            : `${rate}%`}
                    </span>
                </div>
                <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        className="h-full rounded-full bg-[#0ABFBF] transition-all"
                        style={{ width: `${rate}%` }}
                    />
                </div>
                <p className="text-xs text-muted-foreground tabular-nums">
                    {analytics.completed} of {analytics.total} completed
                </p>
            </div>

            {/* Metric tiles */}
            <div className="grid grid-cols-3 gap-4">
                <Tile
                    icon={TrendingUp}
                    label="Avg score"
                    value={
                        analytics.average_score === null
                            ? '—'
                            : `${analytics.average_score}%`
                    }
                    tone={scoreTone(analytics.average_score)}
                />
                <Tile
                    icon={TriangleAlert}
                    label="At risk"
                    value={analytics.at_risk.toString()}
                    hint="unfinished after end"
                    tone={
                        analytics.at_risk > 0
                            ? 'text-amber-600 dark:text-amber-400'
                            : undefined
                    }
                />
                <Tile
                    icon={UserX}
                    label="Dropped"
                    value={analytics.dropped.toString()}
                    tone={
                        analytics.dropped > 0
                            ? 'text-rose-600 dark:text-rose-400'
                            : undefined
                    }
                />
            </div>
        </div>
    );
}

function Tile({
    icon: Icon,
    label,
    value,
    hint,
    tone,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    value: string;
    hint?: string;
    tone?: string;
}) {
    return (
        <div className="flex flex-col gap-1.5 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
            <span className="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                <Icon className="size-3.5" />
                {label}
            </span>
            <span
                className={`text-2xl font-semibold tracking-tight tabular-nums ${tone ?? ''}`}
            >
                {value}
            </span>
            {hint && (
                <span className="text-[11px] text-muted-foreground">
                    {hint}
                </span>
            )}
        </div>
    );
}
