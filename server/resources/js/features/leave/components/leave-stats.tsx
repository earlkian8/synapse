import {
    CalendarDays,
    CalendarRange,
    Hourglass,
    PalmtreeIcon,
} from 'lucide-react';
import { StatTiles } from '@/components/data-table';
import type { LeaveStats } from '../types';

/** Leave's four headline counts. */
export function LeaveStatsCards({ stats }: { stats: LeaveStats }) {
    return (
        <StatTiles
            tiles={[
                {
                    key: 'pending',
                    label: 'Awaiting review',
                    value: stats.pending.toLocaleString(),
                    icon: Hourglass,
                    accent: 'amber',
                },
                {
                    key: 'today',
                    label: 'On leave today',
                    value: stats.on_leave_today.toLocaleString(),
                    icon: PalmtreeIcon,
                    accent: 'teal',
                },
                {
                    key: 'upcoming',
                    label: 'Upcoming',
                    value: stats.upcoming.toLocaleString(),
                    icon: CalendarDays,
                    accent: 'indigo',
                },
                {
                    key: 'days',
                    label: 'Days this month',
                    value: stats.days_this_month.toLocaleString(),
                    icon: CalendarRange,
                    accent: 'emerald',
                },
            ]}
        />
    );
}
