import { Gauge, Sparkles, TrendingUp, Users } from 'lucide-react';
import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import { formatScore, scoreTone } from '../constants';
import type { ReadinessRun } from '../types';

/** Headline metric cards for the latest assessment run. */
export function ReadinessStatsCards({ run }: { run: ReadinessRun }) {
    const readyShare =
        run.employees_scored > 0
            ? Math.round((run.high_count / run.employees_scored) * 100)
            : 0;

    return (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Card
                icon={Users}
                label="Assessed"
                value={run.employees_scored.toLocaleString()}
                hint="active employees scored"
            />
            <Card
                icon={Sparkles}
                label="Promotion-ready"
                value={run.high_count.toLocaleString()}
                hint={`${readyShare}% in the high tier`}
                valueClassName="text-emerald-600 dark:text-emerald-400"
            />
            <Card
                icon={TrendingUp}
                label="Developing"
                value={run.medium_count.toLocaleString()}
                hint="medium tier — watch & grow"
                valueClassName="text-amber-600 dark:text-amber-400"
            />
            <Card
                icon={Gauge}
                label="Average readiness"
                value={formatScore(run.average_score)}
                hint="mean score across cohort"
                valueClassName={
                    run.average_score === null
                        ? undefined
                        : scoreTone(run.average_score)
                }
            />
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
