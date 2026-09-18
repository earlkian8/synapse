import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarCheck, CheckCheck, RefreshCw, UserRound } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { AssignScheduleDialog } from '@/features/attendance/components/assign-schedule-dialog';
import { AttendanceStatsCards } from '@/features/attendance/components/attendance-stats';
import { AttendanceToolbar } from '@/features/attendance/components/attendance-toolbar';
import { AttendanceViewTabs } from '@/features/attendance/components/attendance-view-tabs';
import { ExceptionsPanel } from '@/features/attendance/components/exceptions-panel';
import { FileRequestDialog } from '@/features/attendance/components/file-request-dialog';
import type { RequestDraft } from '@/features/attendance/components/file-request-dialog';
import { ManualEntryDialog } from '@/features/attendance/components/manual-entry-dialog';
import { MonthlyReportTable } from '@/features/attendance/components/monthly-report-table';
import { PeriodsPanel } from '@/features/attendance/components/periods-panel';
import { RecordDetailDialog } from '@/features/attendance/components/record-detail-dialog';
import { RequestReviewDialog } from '@/features/attendance/components/request-review-dialog';
import { RequestsInbox } from '@/features/attendance/components/requests-inbox';
import { RosterEntryDialog } from '@/features/attendance/components/roster-entry-dialog';
import type { RosterTarget } from '@/features/attendance/components/roster-entry-dialog';
import {
    AssignScheduleButton,
    RosterGrid,
} from '@/features/attendance/components/roster-grid';
import { TodayLogTable } from '@/features/attendance/components/today-log-table';
import { WeeklyGrid } from '@/features/attendance/components/weekly-grid';
import { periodRange } from '@/features/attendance/constants';
import { useAttendanceFilters } from '@/features/attendance/hooks/use-attendance-filters';
import { attendanceRoutes } from '@/features/attendance/routes';
import type {
    AttendanceIndexPageProps,
    AttendanceRecord,
    AttendanceRequestItem,
    DayRequest,
} from '@/features/attendance/types';

export default function AttendanceIndex() {
    const {
        records,
        week,
        report,
        roster,
        requests,
        periods,
        stats,
        options,
        can,
        filters,
    } = usePage<AttendanceIndexPageProps>().props;
    const {
        setTab,
        setDate,
        goToDay,
        setSearch,
        setStatus,
        setDepartment,
        setRequestStatus,
        setRequestType,
    } = useAttendanceFilters(filters);

    const [detail, setDetail] = useState<AttendanceRecord | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);

    const [manual, setManual] = useState<AttendanceRecord | null>(null);
    const [manualOpen, setManualOpen] = useState(false);

    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const [approveOpen, setApproveOpen] = useState(false);
    const [approving, setApproving] = useState(false);

    const [reapplyOpen, setReapplyOpen] = useState(false);
    const [reapplying, setReapplying] = useState(false);

    const [rosterTarget, setRosterTarget] = useState<RosterTarget | null>(null);
    const [rosterOpen, setRosterOpen] = useState(false);
    const [assignOpen, setAssignOpen] = useState(false);

    // Requests (ADR 0039): the one being reviewed, and one being filed for somebody.
    const [review, setReview] = useState<AttendanceRequestItem | null>(null);
    const [reviewOpen, setReviewOpen] = useState(false);
    const [fileDraft, setFileDraft] = useState<RequestDraft | null>(null);
    const [fileOpen, setFileOpen] = useState(false);

    // The day's figures are about what happened; the roster, the requests and
    // the periods are about something else.
    const showsDays = ['today', 'weekly', 'monthly'].includes(filters.tab);

    // The period on screen — the day, the week or the month — is what a bulk
    // re-apply covers.
    const period = periodRange(filters.date, filters.tab);

    // A GET download that mirrors the on-screen tab and its active filters.
    const exportUrl = useMemo(() => {
        const params = new URLSearchParams({
            tab: filters.tab,
            date: filters.date,
        });

        if (filters.search) {
            params.set('search', filters.search);
        }

        if (filters.status && filters.status !== 'all') {
            params.set('status', filters.status);
        }

        if (filters.department) {
            params.set('department', String(filters.department));
        }

        return `${attendanceRoutes.export}?${params.toString()}`;
    }, [filters]);

    // The payroll period summary for the month on screen (ADR 0038): one row per
    // employee, every bucket in minutes. Offered on the monthly tab.
    const periodExportUrl = useMemo(() => {
        const params = new URLSearchParams({
            tab: 'period',
            date: filters.date,
        });

        if (filters.search) {
            params.set('search', filters.search);
        }

        if (filters.department) {
            params.set('department', String(filters.department));
        }

        return `${attendanceRoutes.export}?${params.toString()}`;
    }, [filters.date, filters.search, filters.department]);

    const approveAllPending = () =>
        router.patch(
            attendanceRoutes.approveAll,
            {},
            {
                preserveScroll: true,
                onStart: () => setApproving(true),
                onFinish: () => {
                    setApproving(false);
                    setApproveOpen(false);
                },
            },
        );

    const openReview = (request: AttendanceRequestItem) => {
        setDetailOpen(false);
        setReview(request);
        setReviewOpen(true);
    };

    // A request listed in the day modal carries less than the inbox's; the
    // review modal fetches the rest.
    const openDayRequest = (request: DayRequest, record: AttendanceRecord) =>
        openReview({
            ...request,
            id: 0,
            attachment: null,
            reviewed_at: null,
            created_at: null,
            created_human: null,
            locked_period: record.locked_period ?? null,
            employee: record.employee,
            can: { review: false, cancel: false },
        });

    const openDetail = (record: AttendanceRecord) => {
        setDetail(record);
        setDetailOpen(true);
    };

    const openManual = (record: AttendanceRecord | null) => {
        setDetailOpen(false);
        setManual(record);
        setManualOpen(true);
    };

    const approve = (record: AttendanceRecord) => {
        if (!record.hashid) {
            return;
        }

        router.patch(
            attendanceRoutes.approve(record.hashid),
            {},
            { preserveScroll: true, onSuccess: () => setDetailOpen(false) },
        );
    };

    const reapply = (record: AttendanceRecord) => {
        if (!record.hashid) {
            return;
        }

        router.patch(
            attendanceRoutes.reapply(record.hashid),
            {},
            { preserveScroll: true, onSuccess: () => setDetailOpen(false) },
        );
    };

    const reapplyPeriod = () =>
        router.patch(
            attendanceRoutes.reapplyRange,
            {
                from: period.from,
                to: period.to,
                department: filters.department,
            },
            {
                preserveScroll: true,
                onStart: () => setReapplying(true),
                onFinish: () => {
                    setReapplying(false);
                    setReapplyOpen(false);
                },
            },
        );

    const askDelete = (record: AttendanceRecord) => {
        setDetail(record);
        setConfirmOpen(true);
    };

    const remove = () => {
        if (!detail?.hashid) {
            return;
        }

        router.delete(attendanceRoutes.destroy(detail.hashid), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirmOpen(false);
                setDetailOpen(false);
            },
        });
    };

    return (
        <>
            <Head title="Attendance" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Attendance
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            The team's daily time records — who's in, who's
                            late, and who's out.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {can.manage && showsDays && stats.pending > 0 && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setApproveOpen(true)}
                            >
                                <CheckCheck className="size-4" />
                                Sign off{' '}
                                <span className="tabular-nums">
                                    {stats.pending}
                                </span>{' '}
                                {stats.pending === 1 ? 'day' : 'days'}
                            </Button>
                        )}
                        {filters.tab === 'roster' && can.manageRoster && (
                            <AssignScheduleButton
                                onClick={() => setAssignOpen(true)}
                            />
                        )}
                        {can.manage && showsDays && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setReapplyOpen(true)}
                            >
                                <RefreshCw className="size-4" />
                                Re-apply rules
                            </Button>
                        )}
                        <Button variant="outline" size="sm" asChild>
                            <Link href={attendanceRoutes.me}>
                                <UserRound className="size-4" />
                                My attendance
                            </Link>
                        </Button>
                    </div>
                </div>

                {showsDays && <AttendanceStatsCards stats={stats} />}

                <AttendanceViewTabs
                    value={filters.tab}
                    visible={{
                        roster: can.viewRoster,
                        requests: can.reviewRequests,
                        periods: can.managePeriods,
                    }}
                    pendingRequests={stats.pending_requests}
                    onChange={setTab}
                />

                <div className="flex flex-col gap-4">
                    {filters.tab === 'requests' &&
                        (requests ? (
                            <RequestsInbox
                                requests={requests}
                                filters={filters}
                                departments={options.departments}
                                canFile={can.manage}
                                onSearch={setSearch}
                                onDepartment={setDepartment}
                                onStatus={setRequestStatus}
                                onType={setRequestType}
                                onOpen={openReview}
                                onFile={() => {
                                    setFileDraft({ type: 'correction' });
                                    setFileOpen(true);
                                }}
                            />
                        ) : (
                            <Loading />
                        ))}

                    {filters.tab === 'periods' &&
                        (periods ? (
                            <PeriodsPanel
                                periods={periods}
                                canUnlock={can.unlockPeriods}
                            />
                        ) : (
                            <Loading />
                        ))}

                    {(showsDays || filters.tab === 'roster') && (
                        <AttendanceToolbar
                            filters={filters}
                            departments={options.departments}
                            canManage={can.manage}
                            exportUrl={exportUrl}
                            periodExportUrl={
                                filters.tab === 'monthly'
                                    ? periodExportUrl
                                    : undefined
                            }
                            onDate={setDate}
                            onSearch={setSearch}
                            onStatus={setStatus}
                            onDepartment={setDepartment}
                            onManualEntry={() => openManual(null)}
                        />
                    )}

                    {filters.tab === 'today' && (
                        <TodayTab
                            records={records}
                            canManage={can.manage}
                            onOpen={openDetail}
                            onResolve={openManual}
                        />
                    )}

                    {filters.tab === 'weekly' &&
                        (week ? (
                            <WeeklyGrid week={week} onPickDay={goToDay} />
                        ) : (
                            <Loading />
                        ))}

                    {filters.tab === 'monthly' &&
                        (report ? (
                            <MonthlyReportTable report={report} />
                        ) : (
                            <Loading />
                        ))}

                    {filters.tab === 'roster' &&
                        (roster ? (
                            <RosterGrid
                                roster={roster}
                                canManage={can.manageRoster}
                                onPickCell={(row, cell) => {
                                    setRosterTarget({
                                        employee: row.employee,
                                        cell,
                                    });
                                    setRosterOpen(true);
                                }}
                            />
                        ) : (
                            <Loading />
                        ))}
                </div>
            </div>

            <RecordDetailDialog
                record={detail}
                canManage={can.manage}
                open={detailOpen}
                onOpenChange={setDetailOpen}
                onEdit={openManual}
                onApprove={approve}
                onReapply={reapply}
                onDelete={askDelete}
                onOpenRequest={openDayRequest}
            />

            <RequestReviewDialog
                request={review}
                open={reviewOpen}
                onOpenChange={setReviewOpen}
            />

            <FileRequestDialog
                draft={fileDraft}
                employees={options.employees}
                open={fileOpen}
                onOpenChange={setFileOpen}
            />

            <ManualEntryDialog
                record={manual}
                employees={options.employees}
                open={manualOpen}
                onOpenChange={setManualOpen}
            />

            <RosterEntryDialog
                target={rosterTarget}
                schedules={options.schedules}
                open={rosterOpen}
                onOpenChange={setRosterOpen}
            />

            <AssignScheduleDialog
                employees={roster?.rows.map((row) => row.employee) ?? []}
                schedules={options.schedules}
                policies={options.policies}
                action={attendanceRoutes.rosterAssign}
                open={assignOpen}
                onOpenChange={setAssignOpen}
            />

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title="Delete this record?"
                description="The attendance record and its punches are permanently removed."
                confirmLabel="Delete"
                destructive
                processing={processing}
                onConfirm={remove}
            />

            <ConfirmDialog
                open={approveOpen}
                onOpenChange={setApproveOpen}
                title={`Sign off ${stats.pending} ${stats.pending === 1 ? 'day' : 'days'}?`}
                description="Every day awaiting sign-off is approved as it stands — its overtime counts as approved. Your own days, and days in a locked period, are left."
                confirmLabel="Sign off all"
                processing={approving}
                onConfirm={approveAllPending}
            />

            <ConfirmDialog
                open={reapplyOpen}
                onOpenChange={setReapplyOpen}
                title={`Re-apply current rules to ${periodText(period.from, period.to)}?`}
                description={
                    <>
                        Every recorded day in this period
                        {filters.department
                            ? ' for the selected department'
                            : ''}{' '}
                        is judged again by each employee&apos;s current
                        schedule, attendance policy and the holiday calendar.
                        Punches stay as they are; lateness, overtime and status
                        can change. Until you do this, a day keeps the rules it
                        was recorded with.
                    </>
                }
                confirmLabel="Re-apply"
                processing={reapplying}
                onConfirm={reapplyPeriod}
            />
        </>
    );
}

/** "Sep 14" or "Sep 8 – Sep 14". */
function periodText(from: string, to: string): string {
    const format = (date: string) =>
        new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
        });

    return from === to ? format(from) : `${format(from)} – ${format(to)}`;
}

function TodayTab({
    records,
    canManage,
    onOpen,
    onResolve,
}: {
    records: AttendanceRecord[];
    canManage: boolean;
    onOpen: (record: AttendanceRecord) => void;
    onResolve: (record: AttendanceRecord) => void;
}) {
    if (records.length === 0) {
        return <EmptyState />;
    }

    return (
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <TodayLogTable records={records} onOpen={onOpen} />
            <ExceptionsPanel
                records={records}
                canManage={canManage}
                onResolve={onResolve}
                onOpen={onOpen}
            />
        </div>
    );
}

function Loading() {
    return (
        <div className="flex items-center justify-center rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 dark:border-sidebar-border">
            <span className="text-sm text-muted-foreground">Loading…</span>
        </div>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <CalendarCheck className="size-5" />
            </span>
            <p className="text-sm font-medium">No employees match this view</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Try a different date, status, or department filter.
            </p>
        </div>
    );
}

AttendanceIndex.layout = {
    breadcrumbs: [{ title: 'Attendance', href: '/attendance' }],
};
