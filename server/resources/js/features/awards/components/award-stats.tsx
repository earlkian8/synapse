import { Award, CalendarHeart, Sparkles, Users } from 'lucide-react';
import { StatTiles } from '@/components/data-table';
import type { AwardStats } from '../types';

/** Recognition's four headline counts. */
export function AwardStatsCards({ stats }: { stats: AwardStats }) {
    return (
        <StatTiles
            tiles={[
                {
                    key: 'total',
                    label: 'Recognitions',
                    value: stats.total.toLocaleString(),
                    icon: Award,
                    accent: 'teal',
                    hint: 'all-time',
                },
                {
                    key: 'month',
                    label: 'This month',
                    value: stats.this_month.toLocaleString(),
                    icon: CalendarHeart,
                    accent: 'rose',
                },
                {
                    key: 'people',
                    label: 'People recognised',
                    value: stats.recognized.toLocaleString(),
                    icon: Users,
                    accent: 'emerald',
                },
                {
                    key: 'types',
                    label: 'Award types',
                    value: stats.types.toLocaleString(),
                    icon: Sparkles,
                    accent: 'amber',
                    hint: 'active',
                },
            ]}
        />
    );
}
