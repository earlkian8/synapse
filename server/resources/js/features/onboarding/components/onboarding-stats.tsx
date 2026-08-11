import {
    AlarmClock,
    CalendarCheck2,
    CircleUserRound,
    Flag,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import type { OnboardingStats } from '../types';

type Card = {
    key: keyof OnboardingStats;
    label: string;
    icon: ComponentType<{ className?: string }>;
    accent: string;
};

const CARDS: Card[] = [
    {
        key: 'active',
        label: 'In onboarding',
        icon: CircleUserRound,
        accent: 'text-[#0ABFBF] bg-[#0ABFBF]/10',
    },
    {
        key: 'overdue_tasks',
        label: 'Overdue tasks',
        icon: AlarmClock,
        accent: 'text-rose-600 bg-rose-500/10',
    },
    {
        key: 'completing_soon',
        label: 'Due this week',
        icon: Flag,
        accent: 'text-amber-600 bg-amber-500/10',
    },
    {
        key: 'completed_this_month',
        label: 'Completed this month',
        icon: CalendarCheck2,
        accent: 'text-emerald-600 bg-emerald-500/10',
    },
];

export function OnboardingStatsCards({ stats }: { stats: OnboardingStats }) {
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
