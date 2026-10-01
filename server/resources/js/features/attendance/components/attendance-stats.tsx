import { Clock3, Hourglass, Palmtree, UserCheck, UserX } from 'lucide-react';
import { StatTiles } from '@/components/data-table';
import type { AttendanceStats } from '../types';

/** Where the day on screen stands: who's in, late, out, and how long they worked. */
export function AttendanceStatsCards({ stats }: { stats: AttendanceStats }) {
    return (
        <StatTiles
            tiles={[
                {
                    key: 'present',
                    label: 'Present',
                    value: stats.present.toLocaleString(),
                    icon: UserCheck,
                    accent: 'emerald',
                },
                {
                    key: 'late',
                    label: 'Late',
                    value: stats.late.toLocaleString(),
                    icon: Clock3,
                    accent: 'amber',
                },
                {
                    key: 'absent',
                    label: 'Absent',
                    value: stats.absent.toLocaleString(),
                    icon: UserX,
                    accent: 'rose',
                },
                {
                    key: 'on_leave',
                    label: 'On leave',
                    value: stats.on_leave.toLocaleString(),
                    icon: Palmtree,
                    accent: 'teal',
                },
                {
                    key: 'avg_hours',
                    label: 'Avg hours',
                    value: stats.avg_hours.toLocaleString(),
                    hint: 'h',
                    icon: Hourglass,
                    accent: 'indigo',
                },
            ]}
        />
    );
}
