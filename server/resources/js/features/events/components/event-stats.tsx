import { CalendarClock, CalendarDays, Users, Video } from 'lucide-react';
import { StatTiles } from '@/components/data-table';
import type { EventStats } from '../types';

/** Events' four headline counts. */
export function EventStatsCards({ stats }: { stats: EventStats }) {
    return (
        <StatTiles
            tiles={[
                {
                    key: 'upcoming',
                    label: 'Upcoming',
                    value: stats.upcoming.toLocaleString(),
                    icon: CalendarClock,
                    accent: 'sky',
                },
                {
                    key: 'total',
                    label: 'Events & meetings',
                    value: stats.total.toLocaleString(),
                    icon: CalendarDays,
                    accent: 'teal',
                },
                {
                    key: 'meetings',
                    label: 'Meetings',
                    value: stats.meetings.toLocaleString(),
                    icon: Video,
                    accent: 'violet',
                },
                {
                    key: 'invitations',
                    label: 'Invitations',
                    value: stats.invitations.toLocaleString(),
                    icon: Users,
                    accent: 'emerald',
                },
            ]}
        />
    );
}
