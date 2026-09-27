import { Activity, CalendarClock, GraduationCap, Trophy } from 'lucide-react';
import { StatTiles } from '@/components/data-table';
import type { TrainingStats } from '../types';

/** Training's four headline counts. */
export function TrainingStatsCards({ stats }: { stats: TrainingStats }) {
    return (
        <StatTiles
            tiles={[
                {
                    key: 'ongoing',
                    label: 'Ongoing',
                    value: stats.ongoing.toLocaleString(),
                    icon: Activity,
                    accent: 'emerald',
                },
                {
                    key: 'upcoming',
                    label: 'Upcoming',
                    value: stats.upcoming.toLocaleString(),
                    icon: CalendarClock,
                    accent: 'sky',
                },
                {
                    key: 'enrolled',
                    label: 'Active enrollments',
                    value: stats.enrolled.toLocaleString(),
                    icon: GraduationCap,
                    accent: 'teal',
                },
                {
                    key: 'completed',
                    label: 'Completions',
                    value: stats.completed.toLocaleString(),
                    icon: Trophy,
                    accent: 'amber',
                },
            ]}
        />
    );
}
