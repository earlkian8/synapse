import { HeartHandshake, ShieldCheck, UserCheck, Wallet } from 'lucide-react';
import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import { formatPesoCompact } from '../constants';
import type { BenefitStats } from '../types';

type Card = {
    key: keyof BenefitStats;
    label: string;
    icon: ComponentType<{ className?: string }>;
    accent: string;
    money?: boolean;
};

const CARDS: Card[] = [
    {
        key: 'plans',
        label: 'Active plans',
        icon: HeartHandshake,
        accent: 'text-[#0ABFBF] bg-[#0ABFBF]/10',
    },
    {
        key: 'enrolled',
        label: 'Employees covered',
        icon: UserCheck,
        accent: 'text-emerald-600 bg-emerald-500/10',
    },
    {
        key: 'monthly_employer',
        label: 'Employer cost / mo',
        icon: Wallet,
        accent: 'text-indigo-600 bg-indigo-500/10',
        money: true,
    },
    {
        key: 'monthly_employee',
        label: 'Employee cost / mo',
        icon: ShieldCheck,
        accent: 'text-amber-600 bg-amber-500/10',
        money: true,
    },
];

/** The KPI bar atop the Benefits workspace — coverage + monthly cost rollups. */
export function BenefitStatsCards({ stats }: { stats: BenefitStats }) {
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
