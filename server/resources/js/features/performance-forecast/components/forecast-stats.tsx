import { ArrowUpCircle, Gauge, LineChart, Users } from 'lucide-react';
import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import { formatConfidence, formatRating, ratingTone } from '../constants';
import type { ForecastRun } from '../types';

/** Headline metric cards for the latest forecast run. */
export function ForecastStatsCards({ run }: { run: ForecastRun }) {
    const exceedsShare =
        run.employees_scored > 0
            ? Math.round((run.exceeds_count / run.employees_scored) * 100)
            : 0;

    return (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Card
                icon={Users}
                label="Forecasted"
                value={run.employees_scored.toLocaleString()}
                hint="active employees projected"
            />
            <Card
                icon={ArrowUpCircle}
                label="Exceeding"
                value={run.exceeds_count.toLocaleString()}
                hint={`${exceedsShare}% projected to exceed`}
                valueClassName="text-emerald-600 dark:text-emerald-400"
            />
            <Card
                icon={LineChart}
                label="Average rating"
                value={formatRating(run.average_rating)}
                hint="mean predicted score / 100"
                valueClassName={
                    run.average_rating === null
                        ? undefined
                        : ratingTone(run.average_rating)
                }
            />
            <Card
                icon={Gauge}
                label="Avg confidence"
                value={formatConfidence(run.average_confidence)}
                hint="how much rests on real history"
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
