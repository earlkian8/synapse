import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarCheck, UserRound } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { AttendanceCard } from '@/features/attendance/components/attendance-card';
import { AttendanceRow } from '@/features/attendance/components/attendance-row';
import { AttendanceStatsCards } from '@/features/attendance/components/attendance-stats';
import { AttendanceToolbar } from '@/features/attendance/components/attendance-toolbar';
import type { AttendanceView } from '@/features/attendance/components/attendance-toolbar';
import { ConfirmDialog } from '@/features/attendance/components/confirm-dialog';
import { ManualEntrySheet } from '@/features/attendance/components/manual-entry-sheet';
import { RecordDetailSheet } from '@/features/attendance/components/record-detail-sheet';
import { useAttendanceFilters } from '@/features/attendance/hooks/use-attendance-filters';
import { attendanceRoutes } from '@/features/attendance/routes';
import type {
    AttendanceIndexPageProps,
    AttendanceRecord,
} from '@/features/attendance/types';

export default function AttendanceIndex() {
    const { records, stats, options, can, filters } =
        usePage<AttendanceIndexPageProps>().props;
    const { setDate, setSearch, setStatus, setDepartment } =
        useAttendanceFilters(filters);

    const [view, setView] = useState<AttendanceView>('board');

    const [detail, setDetail] = useState<AttendanceRecord | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);

    const [manual, setManual] = useState<AttendanceRecord | null>(null);
    const [manualOpen, setManualOpen] = useState(false);

    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

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
                    <Button variant="outline" size="sm" asChild>
                        <Link href={attendanceRoutes.me}>
                            <UserRound className="size-4" />
                            My attendance
                        </Link>
                    </Button>
                </div>

                <AttendanceStatsCards stats={stats} />

                <div className="flex flex-col gap-4">
                    <AttendanceToolbar
                        filters={filters}
                        departments={options.departments}
                        view={view}
                        canManage={can.manage}
                        onDate={setDate}
                        onSearch={setSearch}
                        onStatus={setStatus}
                        onDepartment={setDepartment}
                        onView={setView}
                        onManualEntry={() => openManual(null)}
                    />

                    {records.length === 0 ? (
                        <EmptyState />
                    ) : view === 'board' ? (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {records.map((record) => (
                                <AttendanceCard
                                    key={record.employee?.id ?? record.id}
                                    record={record}
                                    onOpen={openDetail}
                                />
                            ))}
                        </div>
                    ) : (
                        <div className="divide-y divide-border overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                            {records.map((record) => (
                                <AttendanceRow
                                    key={record.employee?.id ?? record.id}
                                    record={record}
                                    onOpen={openDetail}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <RecordDetailSheet
                record={detail}
                canManage={can.manage}
                open={detailOpen}
                onOpenChange={setDetailOpen}
                onEdit={openManual}
                onApprove={approve}
                onDelete={askDelete}
            />

            <ManualEntrySheet
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
        </>
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
