import { CheckCircle2, ClipboardList, FileEdit, Gauge } from 'lucide-react';
import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import { formatScore, ratingLabelForScore, scoreTone } from '../constants';
import type { PerformanceStats } from '../types';

/** The headline metric cards for the performance overview. */
export function PerformanceStatsCards({ stats }: { stats: PerformanceStats }) {
    return (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Card
                icon={ClipboardList}
                label="Evaluations"
                value={stats.total.toLocaleString()}
                hint="across all periods"
            />
            <Card
                icon={FileEdit}
                label="In progress"
                value={stats.draft.toLocaleString()}
                hint="drafts being scored"
            />
            <Card
                icon={CheckCircle2}
                label="Awaiting sign-off"
                value={stats.submitted.toLocaleString()}
                hint="submitted, not acknowledged"
            />
            <Card
                icon={Gauge}
                label="Average score"
                value={formatScore(stats.average_score)}
                hint={
                    ratingLabelForScore(stats.average_score) ?? 'no scores yet'
                }
                valueClassName={scoreTone(stats.average_score)}
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
