import {
    CalendarCheck,
    CalendarCog,
    QrCode,
    Tablet,
    UserRound,
} from 'lucide-react';
import { ModuleNav } from '@/components/module-nav';
import { rosterRoutes } from '@/features/roster/routes';
import { attendanceRoutes } from '../routes';

export type AttendanceSection = 'me' | 'records' | 'roster' | 'kiosks' | 'qr';

/**
 * Attendance's sections (ADR 0090): one's own time record, then what HR keeps
 * — the time records of what happened, the shift roster they are judged
 * against, the kiosks people without a phone clock in at (ADR 0092) and the
 * QR stations people scan with their own (ADR 0103). Each
 * shows only to whoever may open it.
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
                    {
                        key: 'kiosks',
                        href: '/attendance/kiosks',
                        label: 'Kiosks',
                        icon: Tablet,
                        permission: 'attendance.kiosks.manage',
                    },
                    {
                        key: 'qr',
                        href: '/attendance/qr',
                        label: 'QR stations',
                        icon: QrCode,
                        permission: 'attendance.qr.manage',
                    },
                ],
            ]}
        />
    );
}
