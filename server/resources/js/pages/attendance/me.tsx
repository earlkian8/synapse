import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { AttendanceStatusBadge } from '@/features/attendance/components/attendance-status-badge';
import { ClockCard } from '@/features/attendance/components/clock-card';
import { PunchTimeline } from '@/features/attendance/components/punch-timeline';
import { formatDuration, formatTime } from '@/features/attendance/constants';
import { attendanceRoutes } from '@/features/attendance/routes';
import type {
    AttendanceRecord,
    MyAttendancePageProps,
} from '@/features/attendance/types';

export default function MyAttendance() {
    const { employee, today, nextExpected, allowed, history, summary, can } =
        usePage<MyAttendancePageProps>().props;

    return (
        <>
            <Head title="My Attendance" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <Link
                        href={attendanceRoutes.index}
                        className="inline-flex w-fit items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-3.5" />
                        Attendance
                    </Link>
                    <h1 className="text-xl font-semibold tracking-tight">
                        My Attendance
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Clock in and out, and review your daily time record.
                    </p>
                </div>

                <div className="grid gap-5 lg:grid-cols-2">
                    {/* Clock + today */}
                    <div className="flex flex-col gap-5">
                        <ClockCard
                            today={today}
                            nextExpected={nextExpected}
                            allowed={allowed}
                            schedule={employee.schedule}
                            canClock={can.clock}
                        />

                        <div className="rounded-xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border">
                            <p className="mb-4 text-xs font-medium text-muted-foreground">
                                Today's punches
                            </p>
                            <PunchTimeline punches={today.punches ?? []} />
                        </div>
                    </div>

                    {/* Summary + history */}
                    <div className="flex flex-col gap-5">
                        <div className="grid grid-cols-2 gap-3">
                            <SummaryStat
                                label="Worked this month"
                                value={`${summary.worked_hours}h`}
                            />
                            <SummaryStat
                                label="Overtime"
                                value={`${summary.overtime_hours}h`}
                            />
                            <SummaryStat
                                label="Late days"
                                value={summary.late_count.toString()}
                            />
                            <SummaryStat
                                label="Absences"
                                value={summary.absent_count.toString()}
                            />
                        </div>

                        <div className="rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                            <p className="border-b border-border px-5 py-3 text-xs font-medium text-muted-foreground">
                                Recent history
                            </p>
                            {history.length === 0 ? (
                                <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                                    No records yet.
                                </p>
                            ) : (
                                <ul className="divide-y divide-border">
                                    {history.map((record) => (
                                        <HistoryItem
                                            key={record.id ?? record.work_date}
                                            record={record}
                                        />
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

function SummaryStat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-1 text-2xl font-semibold tracking-tight tabular-nums">
                {value}
            </p>
        </div>
    );
}

function HistoryItem({ record }: { record: AttendanceRecord }) {
    const date = record.work_date
        ? new Date(`${record.work_date}T00:00:00`).toLocaleDateString(
              undefined,
              { weekday: 'short', month: 'short', day: 'numeric' },
          )
        : '—';

    return (
        <li className="flex items-center gap-3 px-5 py-3">
            <div className="w-24 text-sm font-medium">{date}</div>
            <div className="flex-1 text-sm text-muted-foreground tabular-nums">
                {formatTime(record.first_in_at)} →{' '}
                {formatTime(record.last_out_at)}
            </div>
            <div className="hidden w-16 text-right text-sm font-medium tabular-nums sm:block">
                {record.worked_minutes > 0
                    ? formatDuration(record.worked_minutes)
                    : '—'}
            </div>
            <AttendanceStatusBadge status={record.status} />
        </li>
    );
}

MyAttendance.layout = {
    breadcrumbs: [
        { title: 'Attendance', href: '/attendance' },
        { title: 'My Attendance', href: '/attendance/me' },
    ],
};
