import { Head, Link, router, usePage } from '@inertiajs/react';
import { CheckCheck, RefreshCw, UserRound } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { PageBody, PageHeader } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { AttendanceStatsCards } from '@/features/attendance/components/attendance-stats';
import { AttendanceToolbar } from '@/features/attendance/components/attendance-toolbar';
import { AttendanceViewTabs } from '@/features/attendance/components/attendance-view-tabs';
import { ExceptionsTable } from '@/features/attendance/components/exceptions-table';
import { ManualEntryDialog } from '@/features/attendance/components/manual-entry-dialog';
import { MonthlyReportTable } from '@/features/attendance/components/monthly-report-table';
import { RecordDetailDialog } from '@/features/attendance/components/record-detail-dialog';
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
    const { records, week, report, stats, options, can, filters } =
        usePage<AttendanceIndexPageProps>().props;
    const {
        setTab,
        setDate,
        goToDay,
        setSearch,
        setStatus,
        setDepartment,
        reset,
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

            <PageBody>
                <PageHeader
                    title="Attendance"
                    description="The team's daily time records — who's in, who's late, and who's out."
                    actions={
                        <>
                            {can.manage && stats.pending > 0 && (
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
                        </>
                    }
                />

                <AttendanceStatsCards stats={stats} />

                <div className="flex flex-col gap-3">
                    <AttendanceViewTabs value={filters.tab} onChange={setTab} />

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
                        onReset={reset}
                        onManualEntry={() => openManual(null)}
                    />

                    {filters.tab === 'today' && (
                        <>
                            {records.length > 0 && (
                                <ExceptionsTable
                                    records={records}
                                    canManage={can.manage}
                                    onResolve={openManual}
                                    onOpen={openDetail}
                                />
                            )}
                            <TodayLogTable
                                resetKey={`${filters.date}|${filters.search}|${filters.status}|${filters.department}`}
                                records={records}
                                canManage={can.manage}
                                onOpen={openDetail}
                                onEdit={openManual}
                            />
                        </>
                    )}

                    {filters.tab === 'weekly' &&
                        (week ? (
                            <WeeklyGrid
                                week={week}
                                onPickDay={goToDay}
                                resetKey={`${filters.date}|${filters.search}|${filters.department}`}
                            />
                        ) : (
                            <Loading />
                        ))}

                    {filters.tab === 'monthly' &&
                        (report ? (
                            <MonthlyReportTable
                                resetKey={`${filters.date}|${filters.search}|${filters.department}`}
                                report={report}
                            />
                        ) : (
                            <Loading />
                        ))}
                </div>
            </PageBody>

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
                description="Every day awaiting sign-off is approved as it stands — its overtime counts as approved. Your own days are left."
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

function Loading() {
    return (
        <div className="flex items-center justify-center rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-10 dark:border-sidebar-border">
            <span className="text-sm text-muted-foreground">Loading…</span>
        </div>
    );
}

AttendanceIndex.layout = {
    breadcrumbs: [{ title: 'Attendance', href: '/attendance' }],
};
