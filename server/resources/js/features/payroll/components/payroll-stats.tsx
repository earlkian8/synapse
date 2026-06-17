import { Banknote, CalendarClock, Receipt, Users } from 'lucide-react';
import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import { formatPesoCompact } from '../constants';
import type { PayrollStats } from '../types';

type Card = {
    key: keyof PayrollStats;
    label: string;
    icon: ComponentType<{ className?: string }>;
    accent: string;
    money?: boolean;
};

const CARDS: Card[] = [
    {
        key: 'net',
        label: 'Latest net pay',
        icon: Banknote,
        accent: 'text-emerald-600 bg-emerald-500/10',
        money: true,
    },
    {
        key: 'headcount',
        label: 'Employees paid',
        icon: Users,
        accent: 'text-[#0ABFBF] bg-[#0ABFBF]/10',
    },
    {
        key: 'deductions',
        label: 'Latest deductions',
        icon: Receipt,
        accent: 'text-indigo-600 bg-indigo-500/10',
        money: true,
    },
    {
        key: 'pending',
        label: 'Pending runs',
        icon: CalendarClock,
        accent: 'text-amber-600 bg-amber-500/10',
    },
];

/** The KPI bar atop the payroll workspace — the latest run's headline figures. */
export function PayrollStatsCards({ stats }: { stats: PayrollStats }) {
    return (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {CARDS.map(({ key, label, icon: Icon, accent, money }) => (
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
                        {money
                            ? formatPesoCompact(stats[key])
                            : stats[key].toLocaleString()}
                    </p>
                </div>
            ))}
        </div>
    );
}
