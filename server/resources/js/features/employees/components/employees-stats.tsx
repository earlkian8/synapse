import {
    BadgeCheck,
    CalendarPlus,
    Coffee,
    UserCheck,
    Users,
    UserSquare,
} from 'lucide-react';
import { StatTiles } from '@/components/data-table';
import type { EmployeeStats } from '../types';

/** The workforce's six headline counts. */
export function EmployeesStats({ stats }: { stats: EmployeeStats }) {
    return (
        <StatTiles
            tiles={[
                {
                    key: 'total',
                    label: 'Total employees',
                    value: stats.total.toLocaleString(),
                    icon: Users,
                    accent: 'teal',
                },
                {
                    key: 'active',
                    label: 'Active',
                    value: stats.active.toLocaleString(),
                    icon: UserCheck,
                    accent: 'emerald',
                },
                {
                    key: 'regular',
                    label: 'Regular',
                    value: stats.regular.toLocaleString(),
                    icon: BadgeCheck,
                    accent: 'sky',
                },
                {
                    key: 'probationary',
                    label: 'Probationary',
                    value: stats.probationary.toLocaleString(),
                    icon: UserSquare,
                    accent: 'violet',
                },
                {
                    key: 'on_leave',
                    label: 'On leave',
                    value: stats.on_leave.toLocaleString(),
                    icon: Coffee,
                    accent: 'amber',
                },
                {
                    key: 'new',
                    label: 'New this month',
                    value: stats.new_this_month.toLocaleString(),
                    icon: CalendarPlus,
                    accent: 'indigo',
                },
            ]}
        />
    );
}
