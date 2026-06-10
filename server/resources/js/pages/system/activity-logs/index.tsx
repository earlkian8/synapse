import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { ActivityBulkActionsBar } from '@/features/activity-logs/components/activity-bulk-actions-bar';
import { ActivityDetailSheet } from '@/features/activity-logs/components/activity-detail-sheet';
import { ActivityPagination } from '@/features/activity-logs/components/activity-pagination';
import { ActivityStatsCards } from '@/features/activity-logs/components/activity-stats';
import { ActivityTable } from '@/features/activity-logs/components/activity-table';
import { ActivityToolbar } from '@/features/activity-logs/components/activity-toolbar';
import { ConfirmDialog } from '@/features/activity-logs/components/confirm-dialog';
import { useActivityLogsFilters } from '@/features/activity-logs/hooks/use-activity-logs-filters';
import { activityLogRoutes } from '@/features/activity-logs/routes';
import type {
    ActivityLogEntry,
    ActivityLogsPageProps,
} from '@/features/activity-logs/types';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

export default function ActivityLogsIndex() {
    const { logs, stats, filters } = usePage<ActivityLogsPageProps>().props;
    const { setSearch, setEvent, setPerPage, setPage, toggleSort, reset } =
        useActivityLogsFilters(filters);

    const [selected, setSelected] = useState<number[]>([]);
    const [detailLog, setDetailLog] = useState<ActivityLogEntry | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const rows = logs.data;
    const signature = useMemo(
        () =>
            [
                filters.search,
                filters.event,
                filters.sort,
                filters.direction,
                filters.per_page,
                logs.meta.current_page,
            ].join('|'),
        [filters, logs.meta.current_page],
    );

    // Drop stale selections whenever the underlying result set changes.
    const [selectionScope, setSelectionScope] = useState(signature);

    if (signature !== selectionScope) {
        setSelectionScope(signature);
        setSelected([]);
    }

    const askConfirm = (config: ConfirmConfig) => {
        setConfirm(config);
        setConfirmOpen(true);
    };

    const withProcessing = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => {
            setProcessing(false);
            setConfirmOpen(false);
        },
    };

    const openView = (log: ActivityLogEntry) => {
        setDetailLog(log);
        setDetailOpen(true);
    };

    const deleteLog = (log: ActivityLogEntry) =>
        askConfirm({
            title: 'Delete this log entry?',
            description:
                'This removes the entry from the audit trail and cannot be undone.',
            confirmLabel: 'Delete entry',
            run: () => router.delete(activityLogRoutes.destroy(log.id), withProcessing),
        });

    const clearLogs = () =>
        askConfirm({
            title: 'Clear the entire activity log?',
            description:
                'Every log entry will be permanently deleted. This cannot be undone.',
            confirmLabel: 'Clear all logs',
            run: () => router.delete(activityLogRoutes.clear, withProcessing),
        });

    const bulkDelete = () =>
        askConfirm({
            title: `Delete ${selected.length} log ${
                selected.length === 1 ? 'entry' : 'entries'
            }?`,
            description: 'The selected entries will be permanently removed.',
            confirmLabel: 'Delete',
            run: () =>
                router.post(
                    activityLogRoutes.bulk,
                    { action: 'delete', ids: selected },
                    {
                        preserveScroll: true,
                        onStart: () => setProcessing(true),
                        onFinish: () => {
                            setProcessing(false);
                            setConfirmOpen(false);
                            setSelected([]);
                        },
                    },
                ),
        });

    const toggleAll = (checked: boolean) =>
        setSelected(checked ? rows.map((log) => log.id) : []);

    const toggleRow = (id: number, checked: boolean) =>
        setSelected((current) =>
            checked ? [...current, id] : current.filter((value) => value !== id),
        );

    return (
        <>
            <Head title="Activity Logs" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Activity Logs
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        A chronological audit trail of actions performed across the
                        system.
                    </p>
                </div>

                <ActivityStatsCards stats={stats} />

                <div className="flex flex-col gap-4">
                    <ActivityToolbar
                        filters={filters}
                        onSearch={setSearch}
                        onEvent={setEvent}
                        onReset={reset}
                        onClear={clearLogs}
                    />

                    {selected.length > 0 && (
                        <ActivityBulkActionsBar
                            count={selected.length}
                            onDelete={bulkDelete}
                            onClear={() => setSelected([])}
                        />
                    )}

                    <ActivityTable
                        logs={rows}
                        filters={filters}
                        selected={selected}
                        onToggleSort={toggleSort}
                        onToggleAll={toggleAll}
                        onToggleRow={toggleRow}
                        onView={openView}
                        onDelete={deleteLog}
                    />

                    <ActivityPagination
                        meta={logs.meta}
                        perPage={filters.per_page}
                        onPage={setPage}
                        onPerPage={setPerPage}
                    />
                </div>
            </div>

            <ActivityDetailSheet
                log={detailLog}
                open={detailOpen}
                onOpenChange={setDetailOpen}
            />

            {confirm && (
                <ConfirmDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    title={confirm.title}
                    description={confirm.description}
                    confirmLabel={confirm.confirmLabel}
                    destructive
                    processing={processing}
                    onConfirm={confirm.run}
                />
            )}
        </>
    );
}

ActivityLogsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Activity Logs',
            href: '/system/activity-logs',
        },
    ],
};
