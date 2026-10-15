import { CalendarCheck, CalendarCog, UserRound } from 'lucide-react';
import { ModuleNav } from '@/components/module-nav';
import { rosterRoutes } from '@/features/roster/routes';
import { attendanceRoutes } from '../routes';

export type AttendanceSection = 'me' | 'records' | 'roster';

/**
 * Attendance's sections (ADR 0090): one's own time record, then what HR keeps
 * — the time records of what happened and the shift roster they are judged
 * against. Each shows only to whoever may open it.
 */
export function AttendanceNav({ current }: { current: AttendanceSection }) {
    return (
        <ModuleNav
            label="Attendance sections"
            current={current}
            groups={[
                [
                    {
                        key: 'me',
                        href: attendanceRoutes.me,
                        label: 'My attendance',
                        icon: UserRound,
                        permission: 'attendance.clock',
                    },
                ],
                [
                    {
                        key: 'records',
                        href: attendanceRoutes.index,
                        label: 'Time records',
                        icon: CalendarCheck,
                        permission: 'attendance.view',
                    },
                    {
                        key: 'roster',
                        href: rosterRoutes.index,
                        label: 'Shift roster',
                        icon: CalendarCog,
                        permission: 'attendance.roster.view',
                    },
                ],
            ]}
        />
    );
}
