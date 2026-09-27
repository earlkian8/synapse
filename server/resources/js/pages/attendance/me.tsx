import { Head, usePage } from '@inertiajs/react';
import { CalendarClock, Clock3, Hourglass, Timer, UserX } from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    PageBody,
    PageHeader,
    StatTiles,
    TableCard,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { AttendanceStatusBadge } from '@/features/attendance/components/attendance-status-badge';
import { ClockCard } from '@/features/attendance/components/clock-card';
import { PunchTimeline } from '@/features/attendance/components/punch-timeline';
import { formatDuration, formatTime } from '@/features/attendance/constants';
import { attendanceRoutes } from '@/features/attendance/routes';
import type { MyAttendancePageProps } from '@/features/attendance/types';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';

/** "Mon, Sep 14" for a work date. */
function formatWorkDate(date: string | null): string {
    return date
        ? new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
              weekday: 'short',
              month: 'short',
              day: 'numeric',
          })
        : '—';
}

/**
 * My Attendance: clock in and out, today's punches, this month in four numbers
 * and the recent record as a table.
 */
export default function MyAttendance() {
    const { employee, today, nextExpected, allowed, history, summary, can } =
        usePage<MyAttendancePageProps>().props;
    const timeZone = useOrganizationTimeZone();
    const page = useClientPagination(history, '', 10);

    return (
        <>
            <Head title="My Attendance" />

            <PageBody>
                <PageHeader
                    back={{
                        href: attendanceRoutes.index,
                        label: 'Back to attendance',
                    }}
                    title="My Attendance"
                    description="Clock in and out, and review your daily time record."
                />

                <StatTiles
                    tiles={[
                        {
                            key: 'worked',
                            label: 'Worked this month',
                            value: summary.worked_hours.toLocaleString(),
                            hint: 'h',
                            icon: Hourglass,
                            accent: 'teal',
                        },
                        {
                            key: 'overtime',
                            label: 'Overtime',
                            value: summary.overtime_hours.toLocaleString(),
                            hint: 'h',
                            icon: Timer,
                            accent: 'indigo',
                        },
                        {
                            key: 'late',
                            label: 'Late days',
                            value: summary.late_count.toLocaleString(),
                            icon: Clock3,
                            accent: 'amber',
                        },
                        {
                            key: 'absent',
                            label: 'Absences',
                            value: summary.absent_count.toLocaleString(),
                            icon: UserX,
                            accent: 'rose',
                        },
                    ]}
                />

                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                    {/* Clock + today */}
                    <div className="flex flex-col gap-4">
                        <ClockCard
                            today={today}
                            nextExpected={nextExpected}
                            allowed={allowed}
                            schedule={employee.schedule}
                            canClock={can.clock}
                        />

                        <TableCard title="Today's punches">
                            <div className="px-4 py-3">
                                <PunchTimeline punches={today.punches ?? []} />
                            </div>
                        </TableCard>
                    </div>

                    {/* History */}
                    <div className="flex flex-col gap-3">
                        <TableCard
                            title="Recent history"
                            count={history.length}
                        >
                            <DataTable>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead className="text-right">
                                            In
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Out
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Worked
                                        </TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {history.length === 0 && (
                                        <EmptyTableRow
                                            colSpan={5}
                                            icon={CalendarClock}
                                            title="No records yet"
                                            description="Your days appear here once you clock in."
                                        />
                                    )}

                                    {page.rows.map((record) => (
                                        <TableRow
                                            key={record.id ?? record.work_date}
                                        >
                                            <TableCell className="text-sm font-medium">
                                                {formatWorkDate(
                                                    record.work_date,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right text-sm text-muted-foreground tabular-nums">
                                                {formatTime(
                                                    record.first_in_at,
                                                    timeZone,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right text-sm text-muted-foreground tabular-nums">
                                                {formatTime(
                                                    record.last_out_at,
                                                    timeZone,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right text-sm font-medium tabular-nums">
                                                {record.worked_minutes > 0
                                                    ? formatDuration(
                                                          record.worked_minutes,
                                                      )
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>
                                                <AttendanceStatusBadge
                                                    status={record.status}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </DataTable>
                        </TableCard>

                        <TablePagination
                            meta={page.meta}
                            perPage={page.perPage}
                            onPage={page.setPage}
                            onPerPage={page.setPerPage}
                        />
                    </div>
                </div>
            </PageBody>
        </>
    );
}

MyAttendance.layout = {
    breadcrumbs: [
        { title: 'Attendance', href: '/attendance' },
        { title: 'My Attendance', href: '/attendance/me' },
    ],
};
