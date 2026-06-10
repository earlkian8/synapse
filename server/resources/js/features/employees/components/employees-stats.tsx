import {
    BadgeCheck,
    CalendarPlus,
    Coffee,
    UserCheck,
    Users,
    UserSquare,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import type { EmployeeStats } from '../types';

type Card = {
    key: keyof EmployeeStats;
    label: string;
    icon: ComponentType<{ className?: string }>;
    accent: string;
};

const CARDS: Card[] = [
    {
        key: 'total',
        label: 'Total Employees',
        icon: Users,
        accent: 'text-[#0ABFBF] bg-[#0ABFBF]/10',
    },
    {
        key: 'active',
        label: 'Active',
        icon: UserCheck,
        accent: 'text-emerald-600 bg-emerald-500/10',
    },
    {
        key: 'regular',
        label: 'Regular',
        icon: BadgeCheck,
        accent: 'text-sky-600 bg-sky-500/10',
    },
    {
        key: 'probationary',
        label: 'Probationary',
        icon: UserSquare,
        accent: 'text-violet-600 bg-violet-500/10',
    },
    {
        key: 'on_leave',
        label: 'On leave',
        icon: Coffee,
        accent: 'text-amber-600 bg-amber-500/10',
    },
    {
        key: 'new_this_month',
        label: 'New this month',
        icon: CalendarPlus,
        accent: 'text-indigo-600 bg-indigo-500/10',
    },
];

export function EmployeesStats({ stats }: { stats: EmployeeStats }) {
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
