import { Award, CalendarHeart, Sparkles, Users } from 'lucide-react';
import type { ComponentType } from 'react';
import type { AwardStats } from '../types';

/** The headline metric cards for the recognition overview. */
export function AwardStatsCards({ stats }: { stats: AwardStats }) {
    return (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Card
                icon={Award}
                label="Recognitions"
                value={stats.total.toLocaleString()}
                hint="given all-time"
            />
            <Card
                icon={CalendarHeart}
                label="This month"
                value={stats.this_month.toLocaleString()}
                hint="awarded this month"
            />
            <Card
                icon={Users}
                label="People recognised"
                value={stats.recognized.toLocaleString()}
                hint="distinct employees"
            />
            <Card
                icon={Sparkles}
                label="Award types"
                value={stats.types.toLocaleString()}
                hint="active in the catalogue"
            />
        </div>
    );
}

function Card({
    icon: Icon,
    label,
    value,
    hint,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    value: string;
    hint: string;
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
            <span className="text-2xl font-semibold tracking-tight tabular-nums">
                {value}
            </span>
            <span className="text-xs text-muted-foreground">{hint}</span>
        </div>
    );
}
