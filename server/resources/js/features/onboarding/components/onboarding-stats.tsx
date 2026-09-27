import {
    AlarmClock,
    CalendarCheck2,
    CircleUserRound,
    Flag,
} from 'lucide-react';
import { StatTiles } from '@/components/data-table';
import type { OnboardingStats } from '../types';

/** Onboarding's four headline counts. */
export function OnboardingStatsCards({ stats }: { stats: OnboardingStats }) {
    return (
        <StatTiles
            tiles={[
                {
                    key: 'active',
                    label: 'In onboarding',
                    value: stats.active.toLocaleString(),
                    icon: CircleUserRound,
                    accent: 'teal',
                },
                {
                    key: 'overdue',
                    label: 'Overdue tasks',
                    value: stats.overdue_tasks.toLocaleString(),
                    icon: AlarmClock,
                    accent: 'rose',
                },
                {
                    key: 'soon',
                    label: 'Due this week',
                    value: stats.completing_soon.toLocaleString(),
                    icon: Flag,
                    accent: 'amber',
                },
                {
                    key: 'completed',
                    label: 'Completed this month',
                    value: stats.completed_this_month.toLocaleString(),
                    icon: CalendarCheck2,
                    accent: 'emerald',
                },
            ]}
        />
    );
}
