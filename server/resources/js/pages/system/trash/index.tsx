import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { BulkActionsBar } from '@/features/trash/components/bulk-actions-bar';
import { TrashTable } from '@/features/trash/components/trash-table';
import { TrashToolbar } from '@/features/trash/components/trash-toolbar';
import { trashKey } from '@/features/trash/constants';
import { useTrashFilters } from '@/features/trash/hooks/use-trash-filters';
import { trashRoutes } from '@/features/trash/routes';
import type { TrashItem, TrashPageProps } from '@/features/trash/types';
import { ConfirmDialog } from '@/features/users/components/confirm-dialog';
import { UsersPagination } from '@/features/users/components/users-pagination';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

export default function TrashIndex({
    items,
    summary,
    filters,
}: TrashPageProps) {
    const { setSearch, setType, setPerPage, setPage } =
        useTrashFilters(filters);

    const [selected, setSelected] = useState<string[]>([]);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const rows = items.data;

    // Drop stale selections whenever the result set changes (render-phase reset).
    const signature = [
        filters.search,
        filters.type,
        filters.per_page,
        items.meta.current_page,
    ].join('|');
    const [scope, setScope] = useState(signature);

    if (signature !== scope) {
        setScope(signature);
        setSelected([]);
    }

    const selectedItems = rows.filter((item) =>
        selected.includes(trashKey(item)),
    );

    const clearSelection = () => setSelected([]);

    const askConfirm = (config: ConfirmConfig) => {
        setConfirm(config);
        setConfirmOpen(true);
    };

    const withProcessing = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onSuccess: () => setSelected([]),
        onFinish: () => {
            setProcessing(false);
            setConfirmOpen(false);
        },
    };

    // ── Selection ──────────────────────────────────────────────────────────
    const toggleAll = (checked: boolean) =>
        setSelected(checked ? rows.map(trashKey) : []);

    const toggleRow = (key: string, checked: boolean) =>
        setSelected((current) =>
            checked ? [...current, key] : current.filter((k) => k !== key),
        );

    // ── Single-item actions ────────────────────────────────────────────────
    const restoreOne = (item: TrashItem) => {
        router.post(
            trashRoutes.restore,
            { type: item.type, id: item.id },
            { preserveScroll: true, onSuccess: clearSelection },
        );
    };

    const deleteOne = (item: TrashItem) => {
        askConfirm({
            title: `Permanently delete this ${item.type_label.toLowerCase()}?`,
            description: (
                <>
                    <span className="font-medium text-foreground">
                        {item.title}
                    </span>{' '}
                    will be removed for good. This cannot be undone.
                </>
            ),
            confirmLabel: 'Delete permanently',
            run: () =>
                router.post(
                    trashRoutes.forceDelete,
                    { type: item.type, id: item.id },
                    withProcessing,
                ),
        });
    };

    // ── Bulk actions ───────────────────────────────────────────────────────
    const refsFor = (predicate: (item: TrashItem) => boolean) =>
        selectedItems
            .filter(predicate)
            .map((item) => ({ type: item.type, id: item.id }));

    const bulkRestore = () => {
        const itemsToRestore = refsFor((item) => item.can_restore);

        if (itemsToRestore.length === 0) {
            return;
        }

        router.post(
            trashRoutes.bulk,
            { action: 'restore', items: itemsToRestore },
            { preserveScroll: true, onSuccess: clearSelection },
        );
    };

    const bulkDelete = () => {
        const itemsToDelete = refsFor((item) => item.can_force_delete);

        if (itemsToDelete.length === 0) {
            return;
        }

        askConfirm({
            title: `Permanently delete ${itemsToDelete.length} item(s)?`,
            description:
                'The selected archived records will be removed for good. This cannot be undone.',
            confirmLabel: 'Delete permanently',
            run: () =>
                router.post(
                    trashRoutes.bulk,
                    { action: 'delete', items: itemsToDelete },
                    withProcessing,
                ),
        });
    };

    const emptyTrash = () => {
        askConfirm({
            title: 'Empty the trash?',
            description:
                'Every archived record you are allowed to delete will be permanently removed. This cannot be undone.',
            confirmLabel: 'Empty trash',
            run: () => router.post(trashRoutes.empty, {}, withProcessing),
        });
    };

    return (
        <>
            <Head title="Trash Bin" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Trash Bin
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Restore archived records or delete them permanently.
                        Items stay here until you remove them.
                    </p>
                </div>

                <div className="flex flex-col gap-4">
                    <TrashToolbar
                        filters={filters}
                        summary={summary}
                        onSearch={setSearch}
                        onType={setType}
                        onEmpty={emptyTrash}
                    />

                    {selectedItems.length > 0 && (
                        <BulkActionsBar
                            items={selectedItems}
                            onRestore={bulkRestore}
                            onDelete={bulkDelete}
                            onClear={clearSelection}
                        />
                    )}

                    <TrashTable
                        items={rows}
                        selected={selected}
                        onToggleAll={toggleAll}
                        onToggleRow={toggleRow}
                        onRestore={restoreOne}
                        onDelete={deleteOne}
                    />

                    <UsersPagination
                        meta={items.meta}
                        perPage={filters.per_page}
                        onPage={setPage}
                        onPerPage={setPerPage}
                    />
                </div>
            </div>

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

TrashIndex.layout = {
    breadcrumbs: [
        {
            title: 'Trash Bin',
            href: '/system/trash',
        },
    ],
};
