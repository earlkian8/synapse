import {
    CalendarDays,
    CalendarRange,
    Hourglass,
    PalmtreeIcon,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import type { LeaveStats } from '../types';

type Card = {
    key: keyof LeaveStats;
    label: string;
    icon: ComponentType<{ className?: string }>;
    accent: string;
    suffix?: string;
};

const CARDS: Card[] = [
    {
        key: 'pending',
        label: 'Awaiting review',
        icon: Hourglass,
        accent: 'text-amber-600 bg-amber-500/10',
    },
    {
        key: 'on_leave_today',
        label: 'On leave today',
        icon: PalmtreeIcon,
        accent: 'text-[#0ABFBF] bg-[#0ABFBF]/10',
    },
    {
        key: 'upcoming',
        label: 'Upcoming',
        icon: CalendarDays,
        accent: 'text-indigo-600 bg-indigo-500/10',
    },
    {
        key: 'days_this_month',
        label: 'Days this month',
        icon: CalendarRange,
        accent: 'text-emerald-600 bg-emerald-500/10',
    },
];

export function LeaveStatsCards({ stats }: { stats: LeaveStats }) {
    return (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {CARDS.map(({ key, label, icon: Icon, accent }) => (
                <div
                    key={key}
                    className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm transition-shadow hover:shadow-md dark:border-sidebar-border"
                >
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-muted-foreground">
                            {label}
                        </span>
                        <span
                            className={cn(
                                'flex size-7 items-center justify-center rounded-lg',
                                accent,
                            )}
                        >
                            <Icon className="size-4" />
                        </span>
                    </div>
                    <p className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">
                        {stats[key].toLocaleString()}
                    </p>
                </div>
            ))}
        </div>
    );
}
