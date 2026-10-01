import {
    Archive,
    CheckCircle2,
    PauseCircle,
    ShieldAlert,
    TrendingUp,
    Users,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import type { UserStats } from '../types';

type Card = {
    key: keyof UserStats;
    label: string;
    icon: ComponentType<{ className?: string }>;
    accent: string;
    hint?: string;
};

const CARDS: Card[] = [
    {
        key: 'total',
        label: 'Total Users',
        icon: Users,
        accent: 'text-[#0ABFBF] bg-[#0ABFBF]/10',
    },
    {
        key: 'active',
        label: 'Active',
        icon: CheckCircle2,
        accent: 'text-emerald-600 bg-emerald-500/10',
    },
    {
        key: 'inactive',
        label: 'Inactive',
        icon: PauseCircle,
        accent: 'text-amber-600 bg-amber-500/10',
    },
    {
        key: 'unverified',
        label: 'Unverified',
        icon: ShieldAlert,
        accent: 'text-rose-600 bg-rose-500/10',
    },
    {
        key: 'new_this_month',
        label: 'New this month',
        icon: TrendingUp,
        accent: 'text-indigo-600 bg-indigo-500/10',
    },
    {
        key: 'archived',
        label: 'Archived',
        icon: Archive,
        accent: 'text-neutral-500 bg-neutral-500/10',
    },
];

export function UsersStats({ stats }: { stats: UserStats }) {
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
