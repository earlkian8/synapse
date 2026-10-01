import {
    CalendarClock,
    CircleCheck,
    TriangleAlert,
    UserRoundMinus,
} from 'lucide-react';
import { StatTiles } from '@/components/data-table';
import type { OffboardingStats } from '../types';

/** Offboarding's four headline counts. */
export function OffboardingStatsCards({ stats }: { stats: OffboardingStats }) {
    return (
        <StatTiles
            tiles={[
                {
                    key: 'active',
                    label: 'In offboarding',
                    value: stats.active.toLocaleString(),
                    icon: UserRoundMinus,
                    accent: 'teal',
                },
                {
                    key: 'flagged',
                    label: 'Flagged clearances',
                    value: stats.flagged_items.toLocaleString(),
                    icon: TriangleAlert,
                    accent: 'rose',
                },
                {
                    key: 'soon',
                    label: 'Leaving in 14 days',
                    value: stats.leaving_soon.toLocaleString(),
                    icon: CalendarClock,
                    accent: 'amber',
                },
                {
                    key: 'completed',
                    label: 'Completed this month',
                    value: stats.completed_this_month.toLocaleString(),
                    icon: CircleCheck,
                    accent: 'emerald',
                },
            ]}
        />
    );
}
