import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    PageBody,
    PageHeader,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import { FileLeaveDialog } from '@/features/leave/components/file-leave-dialog';
import { LeaveNav } from '@/features/leave/components/leave-nav';
import { LeaveRequestsTable } from '@/features/leave/components/leave-requests-table';
import { LeaveStatsCards } from '@/features/leave/components/leave-stats';
import { LeaveToolbar } from '@/features/leave/components/leave-toolbar';
import { ReviewRequestDialog } from '@/features/leave/components/review-request-dialog';
import { DEFAULT_FILTERS } from '@/features/leave/constants';
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

    const filtered =
        filters.search !== '' ||
        filters.type !== null ||
        filters.department !== null ||
        filters.status !== DEFAULT_FILTERS.status;
    const page = useClientPagination(
        requests,
        [filters.search, filters.status, filters.type, filters.department].join(
            '|',
        ),
    );

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

            <PageBody>
                <PageHeader
                    title="Leave Management"
                    description="Review time-off requests, track who's out, and keep balances in check."
                    actions={<LeaveNav active="requests" />}
                />

                <LeaveStatsCards stats={stats} />

                <div className="flex flex-col gap-3">
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

                    <LeaveRequestsTable
                        requests={page.rows}
                        canManage={can.manage}
                        filtered={filtered}
                        onOpen={openReview}
                        onApprove={(r) => quickReview(r, 'approve')}
                        onReject={(r) => quickReview(r, 'reject')}
                    />

                    <TablePagination
                        meta={page.meta}
                        perPage={page.perPage}
                        onPage={page.setPage}
                        onPerPage={page.setPerPage}
                    />
                </div>
            </PageBody>

            <FileLeaveDialog
                request={fileRequest}
                employees={options.employees}
                types={options.types}
                open={fileOpen}
                onOpenChange={setFileOpen}
            />

            <ReviewRequestDialog
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

LeaveIndex.layout = {
    breadcrumbs: [{ title: 'Leave Management', href: '/leave' }],
};
