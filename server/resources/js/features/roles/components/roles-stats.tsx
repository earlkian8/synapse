import {
    KeyRound,
    Lock,
    ShieldCheck,
    SlidersHorizontal,
    UserCheck,
    UserMinus,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import type { RoleStats } from '../types';

type Card = {
    key: keyof RoleStats;
    label: string;
    icon: ComponentType<{ className?: string }>;
    accent: string;
};

const CARDS: Card[] = [
    {
        key: 'total',
        label: 'Total Roles',
        icon: ShieldCheck,
        accent: 'text-[#0ABFBF] bg-[#0ABFBF]/10',
    },
    {
        key: 'system',
        label: 'System',
        icon: Lock,
        accent: 'text-indigo-600 bg-indigo-500/10',
    },
    {
        key: 'custom',
        label: 'Custom',
        icon: SlidersHorizontal,
        accent: 'text-emerald-600 bg-emerald-500/10',
    },
    {
        key: 'permissions',
        label: 'Permissions',
        icon: KeyRound,
        accent: 'text-violet-600 bg-violet-500/10',
    },
    {
        key: 'assigned_users',
        label: 'Assigned users',
        icon: UserCheck,
        accent: 'text-sky-600 bg-sky-500/10',
    },
    {
        key: 'unassigned_users',
        label: 'No role',
        icon: UserMinus,
        accent: 'text-amber-600 bg-amber-500/10',
    },
];

export function RolesStats({ stats }: { stats: RoleStats }) {
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
