import { CalendarClock, CalendarDays, Users, Video } from 'lucide-react';
import type { ComponentType } from 'react';
import type { EventStats } from '../types';

/** The headline metric cards for the events overview. */
export function EventStatsCards({ stats }: { stats: EventStats }) {
    return (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Card
                icon={CalendarClock}
                label="Upcoming"
                value={stats.upcoming.toLocaleString()}
                hint="scheduled ahead"
            />
            <Card
                icon={CalendarDays}
                label="Total"
                value={stats.total.toLocaleString()}
                hint="events & meetings"
            />
            <Card
                icon={Video}
                label="Meetings"
                value={stats.meetings.toLocaleString()}
                hint="of the total"
            />
            <Card
                icon={Users}
                label="Invitations"
                value={stats.invitations.toLocaleString()}
                hint="across all events"
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
