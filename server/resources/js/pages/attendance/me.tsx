import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Lock } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { AttendanceStatusBadge } from '@/features/attendance/components/attendance-status-badge';
import { ClockCard } from '@/features/attendance/components/clock-card';
import { FileRequestDialog } from '@/features/attendance/components/file-request-dialog';
import type { RequestDraft } from '@/features/attendance/components/file-request-dialog';
import {
    MyRequests,
    RequestMenu,
} from '@/features/attendance/components/my-requests';
import { PunchTimeline } from '@/features/attendance/components/punch-timeline';
import { RequestReviewDialog } from '@/features/attendance/components/request-review-dialog';
import { formatDuration, formatTime } from '@/features/attendance/constants';
import { attendanceRoutes } from '@/features/attendance/routes';
import type {
    AttendanceRecord,
    AttendanceRequestItem,
    MyAttendancePageProps,
} from '@/features/attendance/types';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';

export default function MyAttendance() {
    const {
        employee,
        today,
        nextExpected,
        allowed,
        history,
        requests,
        summary,
        can,
    } = usePage<MyAttendancePageProps>().props;

    // Asking for what the clock could not capture (ADR 0039).
    const [draft, setDraft] = useState<RequestDraft | null>(null);
    const [fileOpen, setFileOpen] = useState(false);
    const [viewing, setViewing] = useState<AttendanceRequestItem | null>(null);
    const [viewOpen, setViewOpen] = useState(false);

    const ask = (next: RequestDraft) => {
        setDraft(next);
        setFileOpen(true);
    };

    // A correction starts from the day it fixes; `today` is a transient record
    // until the first punch, so its date comes from the card.
    const fixDay = (record: AttendanceRecord) =>
        ask({
            type: 'correction',
            date: record.work_date ?? undefined,
            record,
        });

    return (
        <>
            <Head title="My Attendance" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
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
                            Clock in and out, review your daily time record, and
                            ask for what the clock missed.
                        </p>
                    </div>
                    {can.request && (
                        <RequestMenu
                            onPick={(type) =>
                                ask(
                                    type === 'correction'
                                        ? {
                                              type,
                                              date:
                                                  today.work_date ?? undefined,
                                              record: today,
                                          }
                                        : { type },
                                )
                            }
                        />
                    )}
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
                            <div className="mb-4 flex items-center justify-between gap-3">
                                <p className="text-xs font-medium text-muted-foreground">
                                    Today's punches
                                </p>
                                {can.request && !today.is_locked && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="h-7 text-xs text-muted-foreground"
                                        onClick={() => fixDay(today)}
                                    >
                                        Request a correction
                                    </Button>
                                )}
                            </div>
                            <PunchTimeline punches={today.punches ?? []} />
                        </div>

                        <MyRequests
                            requests={requests}
                            onOpen={(request) => {
                                setViewing(request);
                                setViewOpen(true);
                            }}
                        />
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
                                            onFix={
                                                can.request && !record.is_locked
                                                    ? () => fixDay(record)
                                                    : undefined
                                            }
                                        />
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <FileRequestDialog
                draft={draft}
                open={fileOpen}
                onOpenChange={setFileOpen}
            />

            <RequestReviewDialog
                request={viewing}
                open={viewOpen}
                onOpenChange={setViewOpen}
            />
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

function HistoryItem({
    record,
    onFix,
}: {
    record: AttendanceRecord;
    /** Ask for a correction of this day; absent when it cannot be asked. */
    onFix?: () => void;
}) {
    const timeZone = useOrganizationTimeZone();
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
                {formatTime(record.first_in_at, timeZone)} →{' '}
                {formatTime(record.last_out_at, timeZone)}
            </div>
            <div className="hidden w-16 text-right text-sm font-medium tabular-nums sm:block">
                {record.worked_minutes > 0
                    ? formatDuration(record.worked_minutes)
                    : '—'}
            </div>
            <AttendanceStatusBadge status={record.status} />
            {onFix ? (
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 px-2 text-xs text-muted-foreground"
                    onClick={onFix}
                    aria-label={`Request a correction for ${date}`}
                >
                    Fix
                </Button>
            ) : record.is_locked ? (
                <Lock
                    className="mx-2.5 size-3.5 text-muted-foreground"
                    aria-label={`${date} is in a locked period`}
                />
            ) : null}
        </li>
    );
}

MyAttendance.layout = {
    breadcrumbs: [
        { title: 'Attendance', href: '/attendance' },
        { title: 'My Attendance', href: '/attendance/me' },
    ],
};
