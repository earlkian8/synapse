import { AlertTriangle, Eye, Gauge, Users } from 'lucide-react';
import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import { formatScore, scoreTone, TIER_LABELS } from '../constants';
import type { RiskRun } from '../types';

/** Headline metric cards + a cohort risk-distribution bar for the latest run. */
export function RiskStatsCards({ run }: { run: RiskRun }) {
    const atRiskShare =
        run.employees_scored > 0
            ? Math.round((run.high_count / run.employees_scored) * 100)
            : 0;

    return (
        <div className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Card
                    icon={Users}
                    label="Assessed"
                    value={run.employees_scored.toLocaleString()}
                    hint="active employees scored"
                />
                <Card
                    icon={AlertTriangle}
                    label="High risk"
                    value={run.high_count.toLocaleString()}
                    hint={`${atRiskShare}% of the workforce`}
                    valueClassName="text-rose-600 dark:text-rose-400"
                />
                <Card
                    icon={Eye}
                    label="At watch"
                    value={run.medium_count.toLocaleString()}
                    hint="some risk — worth a check-in"
                    valueClassName="text-amber-600 dark:text-amber-400"
                />
                <Card
                    icon={Gauge}
                    label="Average risk"
                    value={formatScore(run.average_score)}
                    hint="mean score across cohort"
                    valueClassName={
                        run.average_score === null
                            ? undefined
                            : scoreTone(run.average_score)
                    }
                />
            </div>

            <RiskDistributionBar run={run} />
        </div>
    );
}

/** A slim stacked bar showing the low/medium/high split across the cohort. */
function RiskDistributionBar({ run }: { run: RiskRun }) {
    const total = Math.max(1, run.employees_scored);
    const segments = [
        {
            tier: 'high' as const,
            count: run.high_count,
            bar: 'bg-rose-500',
            dot: 'bg-rose-500',
        },
        {
            tier: 'medium' as const,
            count: run.medium_count,
            bar: 'bg-amber-500',
            dot: 'bg-amber-500',
        },
        {
            tier: 'low' as const,
            count: run.low_count,
            bar: 'bg-emerald-500',
            dot: 'bg-emerald-500',
        },
    ];

    return (
        <div className="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
            <div className="flex items-center justify-between">
                <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    Risk distribution
                </span>
                <div className="flex items-center gap-3">
                    {segments.map((s) => (
                        <span
                            key={s.tier}
                            className="flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <span
                                className={cn('size-2 rounded-full', s.dot)}
                            />
                            {TIER_LABELS[s.tier]}
                            <span className="font-medium text-foreground tabular-nums">
                                {s.count}
                            </span>
                        </span>
                    ))}
                </div>
            </div>
            <div className="flex h-2.5 overflow-hidden rounded-full bg-muted">
                {segments.map((s) =>
                    s.count > 0 ? (
                        <div
                            key={s.tier}
                            className={cn(
                                'h-full first:rounded-l-full last:rounded-r-full',
                                s.bar,
                            )}
                            style={{ width: `${(s.count / total) * 100}%` }}
                            title={`${TIER_LABELS[s.tier]}: ${s.count}`}
                        />
                    ) : null,
                )}
            </div>
        </div>
    );
}

function Card({
    icon: Icon,
    label,
    value,
    hint,
    valueClassName,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    value: string;
    hint: string;
    valueClassName?: string;
}) {
    return (
        <div className="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
            <div className="flex items-center gap-2 text-muted-foreground">
                <span className="flex size-7 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                    <Icon className="size-4" />
                </span>
                <span className="text-xs font-medium tracking-wide uppercase">
                    {label}
                </span>
            </div>
            <span
                className={cn(
                    'text-2xl font-semibold tracking-tight tabular-nums',
                    valueClassName,
                )}
            >
                {value}
            </span>
            <span className="text-xs text-muted-foreground">{hint}</span>
        </div>
    );
}
