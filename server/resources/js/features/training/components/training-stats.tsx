import { Activity, CalendarClock, GraduationCap, Trophy } from 'lucide-react';
import type { ComponentType } from 'react';
import type { TrainingStats } from '../types';

/** The headline metric cards for the training overview. */
export function TrainingStatsCards({ stats }: { stats: TrainingStats }) {
    return (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Card
                icon={Activity}
                label="Ongoing"
                value={stats.ongoing.toLocaleString()}
                hint={`${stats.programs} programs total`}
            />
            <Card
                icon={CalendarClock}
                label="Upcoming"
                value={stats.upcoming.toLocaleString()}
                hint="scheduled to start"
            />
            <Card
                icon={GraduationCap}
                label="Active enrollments"
                value={stats.enrolled.toLocaleString()}
                hint="seats currently taken"
            />
            <Card
                icon={Trophy}
                label="Completions"
                value={stats.completed.toLocaleString()}
                hint="finished trainings"
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
