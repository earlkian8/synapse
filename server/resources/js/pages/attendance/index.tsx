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
import { ManualEntryDialog } from '@/features/attendance/components/manual-entry-dialog';
import { MonthlyReportTable } from '@/features/attendance/components/monthly-report-table';
import { RecordDetailDialog } from '@/features/attendance/components/record-detail-dialog';
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
} from '@/features/attendance/types';

export default function AttendanceIndex() {
    const { records, week, report, roster, stats, options, can, filters } =
        usePage<AttendanceIndexPageProps>().props;
    const { setTab, setDate, goToDay, setSearch, setStatus, setDepartment } =
        useAttendanceFilters(filters);

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
                        {can.manage && stats.pending > 0 && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setApproveOpen(true)}
                            >
                                <CheckCheck className="size-4" />
                                Approve{' '}
                                <span className="tabular-nums">
                                    {stats.pending}
                                </span>{' '}
                                pending
                            </Button>
                        )}
                        {filters.tab === 'roster' && can.manageRoster && (
                            <AssignScheduleButton
                                onClick={() => setAssignOpen(true)}
                            />
                        )}
                        {can.manage && (
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

                {/* The day's figures are about what happened; the roster is about
                    what is meant to. */}
                {filters.tab !== 'roster' && (
                    <AttendanceStatsCards stats={stats} />
                )}

                <AttendanceViewTabs
                    value={filters.tab}
                    canViewRoster={can.viewRoster}
                    onChange={setTab}
                />

                <div className="flex flex-col gap-4">
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
                title={`Approve ${stats.pending} pending record${stats.pending === 1 ? '' : 's'}?`}
                description="Every attendance record awaiting sign-off will be approved. This clears the correction and overtime queue."
                confirmLabel="Approve all"
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
