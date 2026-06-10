import {
    Briefcase,
    CalendarClock,
    FileSignature,
    GitBranch,
    UserCheck,
    Users,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import type { RecruitmentStats } from '../types';

type Card = {
    key: keyof RecruitmentStats;
    label: string;
    icon: ComponentType<{ className?: string }>;
    accent: string;
};

const CARDS: Card[] = [
    {
        key: 'open_postings',
        label: 'Open postings',
        icon: Briefcase,
        accent: 'text-[#0ABFBF] bg-[#0ABFBF]/10',
    },
    {
        key: 'total_applicants',
        label: 'Applicants',
        icon: Users,
        accent: 'text-sky-600 bg-sky-500/10',
    },
    {
        key: 'in_pipeline',
        label: 'In pipeline',
        icon: GitBranch,
        accent: 'text-violet-600 bg-violet-500/10',
    },
    {
        key: 'offers',
        label: 'Offers out',
        icon: FileSignature,
        accent: 'text-amber-600 bg-amber-500/10',
    },
    {
        key: 'interviews_upcoming',
        label: 'Interviews ahead',
        icon: CalendarClock,
        accent: 'text-indigo-600 bg-indigo-500/10',
    },
    {
        key: 'hired_this_month',
        label: 'Hired this month',
        icon: UserCheck,
        accent: 'text-emerald-600 bg-emerald-500/10',
    },
];

export function RecruitmentStatsCards({ stats }: { stats: RecruitmentStats }) {
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
