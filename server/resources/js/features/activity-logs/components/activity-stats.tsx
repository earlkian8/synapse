import {
    Activity,
    CalendarDays,
    CalendarRange,
    Clock,
    PlusCircle,
    Trash2,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import type { ActivityStats } from '../types';

type Card = {
    key: keyof ActivityStats;
    label: string;
    icon: ComponentType<{ className?: string }>;
    accent: string;
};

const CARDS: Card[] = [
    {
        key: 'total',
        label: 'Total events',
        icon: Activity,
        accent: 'text-[#0ABFBF] bg-[#0ABFBF]/10',
    },
    {
        key: 'today',
        label: 'Today',
        icon: Clock,
        accent: 'text-indigo-600 bg-indigo-500/10',
    },
    {
        key: 'this_week',
        label: 'This week',
        icon: CalendarDays,
        accent: 'text-sky-600 bg-sky-500/10',
    },
    {
        key: 'this_month',
        label: 'This month',
        icon: CalendarRange,
        accent: 'text-violet-600 bg-violet-500/10',
    },
    {
        key: 'creates',
        label: 'Creates',
        icon: PlusCircle,
        accent: 'text-emerald-600 bg-emerald-500/10',
    },
    {
        key: 'deletions',
        label: 'Deletions',
        icon: Trash2,
        accent: 'text-rose-600 bg-rose-500/10',
    },
];

export function ActivityStatsCards({ stats }: { stats: ActivityStats }) {
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
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
