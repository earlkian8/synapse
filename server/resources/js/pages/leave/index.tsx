import { Head, router, usePage } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/features/leave/components/confirm-dialog';
import { FileLeaveSheet } from '@/features/leave/components/file-leave-sheet';
import { LeaveNav } from '@/features/leave/components/leave-nav';
import { LeaveRequestRow } from '@/features/leave/components/leave-request-row';
import { LeaveStatsCards } from '@/features/leave/components/leave-stats';
import { LeaveToolbar } from '@/features/leave/components/leave-toolbar';
import { ReviewRequestSheet } from '@/features/leave/components/review-request-sheet';
import { useLeaveFilters } from '@/features/leave/hooks/use-leave-filters';
import { leaveRoutes } from '@/features/leave/routes';
import type { LeaveIndexPageProps, LeaveRequest } from '@/features/leave/types';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    run: () => void;
};

export default function LeaveIndex() {
    const { requests, stats, options, can, filters } =
        usePage<LeaveIndexPageProps>().props;
    const { setSearch, setStatus, setType, setDepartment, reset } =
        useLeaveFilters(filters);

    const [fileOpen, setFileOpen] = useState(false);
    const [fileRequest, setFileRequest] = useState<LeaveRequest | null>(null);

    const [reviewOpen, setReviewOpen] = useState(false);
    const [reviewRequest, setReviewRequest] = useState<LeaveRequest | null>(
        null,
    );

    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const withProcessing = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => {
            setProcessing(false);
            setConfirmOpen(false);
        },
    };

    const askConfirm = (config: ConfirmConfig) => {
        setConfirm(config);
        setConfirmOpen(true);
    };

    const openFile = (request: LeaveRequest | null) => {
        setFileRequest(request);
        setFileOpen(true);
    };

    const openReview = (request: LeaveRequest) => {
        setReviewRequest(request);
        setReviewOpen(true);
    };

    const quickReview = (request: LeaveRequest, action: 'approve' | 'reject') =>
        router.patch(
            leaveRoutes.review(request.hashid),
            { action },
            { preserveScroll: true },
        );

    const cancel = (request: LeaveRequest) =>
        askConfirm({
            title: 'Cancel this leave?',
            description:
                'The request is marked cancelled and stops counting against the balance. This cannot be undone.',
            confirmLabel: 'Cancel leave',
            run: () => {
                setReviewOpen(false);
                router.patch(
                    leaveRoutes.cancel(request.hashid),
                    {},
                    withProcessing,
                );
            },
        });

    const remove = (request: LeaveRequest) =>
        askConfirm({
            title: 'Delete this request?',
            description:
                'The leave request is permanently removed from the records.',
            confirmLabel: 'Delete',
            destructive: true,
            run: () => {
                setReviewOpen(false);
                router.delete(
                    leaveRoutes.destroy(request.hashid),
                    withProcessing,
                );
            },
        });

    const editFromReview = (request: LeaveRequest) => {
        setReviewOpen(false);
        openFile(request);
    };

    return (
        <>
            <Head title="Leave Management" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Leave Management
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Review time-off requests, track who's out, and keep
                            balances in check.
                        </p>
                    </div>
                    <LeaveNav active="requests" />
                </div>

                <LeaveStatsCards stats={stats} />

                <div className="flex flex-col gap-4">
                    <LeaveToolbar
                        filters={filters}
                        types={options.types}
                        departments={options.departments}
                        canRequest={can.request}
                        onSearch={setSearch}
                        onStatus={setStatus}
                        onType={setType}
                        onDepartment={setDepartment}
                        onReset={reset}
                        onFile={() => openFile(null)}
                    />

                    {requests.length === 0 ? (
                        <EmptyState />
                    ) : (
                        <div className="divide-y divide-border overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                            {requests.map((request) => (
                                <LeaveRequestRow
                                    key={request.id}
                                    request={request}
                                    canManage={can.manage}
                                    onOpen={openReview}
                                    onApprove={(r) => quickReview(r, 'approve')}
                                    onReject={(r) => quickReview(r, 'reject')}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <FileLeaveSheet
                request={fileRequest}
                employees={options.employees}
                types={options.types}
                open={fileOpen}
                onOpenChange={setFileOpen}
            />

            <ReviewRequestSheet
                request={reviewRequest}
                canRequest={can.request}
                canManage={can.manage}
                open={reviewOpen}
                onOpenChange={setReviewOpen}
                onEdit={editFromReview}
                onCancel={cancel}
                onDelete={remove}
            />

            {confirm && (
                <ConfirmDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    title={confirm.title}
                    description={confirm.description}
                    confirmLabel={confirm.confirmLabel}
                    destructive={confirm.destructive}
                    processing={processing}
                    onConfirm={confirm.run}
                />
            )}
        </>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <CalendarDays className="size-5" />
            </span>
            <p className="text-sm font-medium">Nothing here</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                No leave requests match this view. File one for an employee to
                get started.
            </p>
        </div>
    );
}

LeaveIndex.layout = {
    breadcrumbs: [{ title: 'Leave Management', href: '/leave' }],
};
