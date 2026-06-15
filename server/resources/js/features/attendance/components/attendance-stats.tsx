import { Clock3, Hourglass, Palmtree, UserCheck, UserX } from 'lucide-react';
import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import type { AttendanceStats } from '../types';

type Card = {
    key: keyof AttendanceStats;
    label: string;
    icon: ComponentType<{ className?: string }>;
    accent: string;
    suffix?: string;
};

const CARDS: Card[] = [
    {
        key: 'present',
        label: 'Present',
        icon: UserCheck,
        accent: 'text-emerald-600 bg-emerald-500/10',
    },
    {
        key: 'late',
        label: 'Late',
        icon: Clock3,
        accent: 'text-amber-600 bg-amber-500/10',
    },
    {
        key: 'absent',
        label: 'Absent',
        icon: UserX,
        accent: 'text-rose-600 bg-rose-500/10',
    },
    {
        key: 'on_leave',
        label: 'On leave',
        icon: Palmtree,
        accent: 'text-[#0ABFBF] bg-[#0ABFBF]/10',
    },
    {
        key: 'avg_hours',
        label: 'Avg hours',
        icon: Hourglass,
        accent: 'text-indigo-600 bg-indigo-500/10',
        suffix: 'h',
    },
];

export function AttendanceStatsCards({ stats }: { stats: AttendanceStats }) {
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            {CARDS.map(({ key, label, icon: Icon, accent, suffix }) => (
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
                        {suffix && (
                            <span className="ml-0.5 text-base font-normal text-muted-foreground">
                                {suffix}
                            </span>
                        )}
                    </p>
                </div>
            ))}
        </div>
    );
}
