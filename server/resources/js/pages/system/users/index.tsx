import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { BulkActionsBar } from '@/features/users/components/bulk-actions-bar';
import { ConfirmDialog } from '@/features/users/components/confirm-dialog';
import { ResetPasswordDialog } from '@/features/users/components/reset-password-dialog';
import { UserDetailSheet } from '@/features/users/components/user-detail-sheet';
import { UserFormSheet } from '@/features/users/components/user-form-sheet';
import { UsersPagination } from '@/features/users/components/users-pagination';
import { UsersStats } from '@/features/users/components/users-stats';
import { UsersTable } from '@/features/users/components/users-table';
import { UsersToolbar } from '@/features/users/components/users-toolbar';
import { useUsersFilters } from '@/features/users/hooks/use-users-filters';
import { userRoutes } from '@/features/users/routes';
import type {
    BulkAction,
    ManagedUser,
    UsersPageProps,
} from '@/features/users/types';
import type { Auth } from '@/types';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive: boolean;
    run: () => void;
};

export default function UsersIndex() {
    const { users, stats, filters, auth, assignableRoles, can } = usePage<
        UsersPageProps & { auth: Auth }
    >().props;
    const currentUserId = auth.user.id;
    const { setSearch, setStatus, setPerPage, setPage, toggleSort, reset } =
        useUsersFilters(filters);

    const [selected, setSelected] = useState<number[]>([]);
    const [formUser, setFormUser] = useState<ManagedUser | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [detailUser, setDetailUser] = useState<ManagedUser | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);
    const [passwordUser, setPasswordUser] = useState<ManagedUser | null>(null);
    const [passwordOpen, setPasswordOpen] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const rows = users.data;
    const signature = useMemo(
        () =>
            [
                filters.search,
                filters.status,
                filters.sort,
                filters.direction,
                filters.per_page,
                users.meta.current_page,
            ].join('|'),
        [filters, users.meta.current_page],
    );

    // Drop stale selections whenever the underlying result set changes —
    // render-phase reset avoids a state-syncing effect.
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

    // ── Single-user actions ────────────────────────────────────────────────
    const openCreate = () => {
        setFormUser(null);
        setFormOpen(true);
    };

    const openEdit = (user: ManagedUser) => {
        setDetailOpen(false);
        setFormUser(user);
        setFormOpen(true);
    };

    const openView = (user: ManagedUser) => {
        setDetailUser(user);
        setDetailOpen(true);
    };

    const openResetPassword = (user: ManagedUser) => {
        setPasswordUser(user);
        setPasswordOpen(true);
    };

    const resendVerification = (user: ManagedUser) => {
        router.post(
            userRoutes.resendVerification(user.id),
            {},
            { preserveScroll: true },
        );
    };

    // Guard the destructive self-actions on the client for instant feedback.
    // The backend enforces the same rules as defense-in-depth.
    const isSelf = (user: ManagedUser) => user.id === currentUserId;

    const toggleStatus = (user: ManagedUser) => {
        if (isSelf(user) && user.is_active) {
            toast.error("You can't deactivate your own account.");

            return;
        }

        router.patch(
            userRoutes.status(user.id),
            { is_active: !user.is_active },
            { preserveScroll: true },
        );
    };

    const archive = (user: ManagedUser) => {
        if (isSelf(user)) {
            toast.error("You can't archive your own account.");

            return;
        }

        askConfirm({
            title: `Archive ${user.full_name}?`,
            description:
                'The account will be deactivated and hidden from the main list. You can restore it later from the Archived filter.',
            confirmLabel: 'Archive user',
            destructive: true,
            run: () =>
                router.delete(userRoutes.destroy(user.id), withProcessing),
        });
    };

    const restore = (user: ManagedUser) => {
        router.patch(userRoutes.restore(user.id), {}, { preserveScroll: true });
    };

    const remove = (user: ManagedUser) => {
        if (isSelf(user)) {
            toast.error("You can't delete your own account.");

            return;
        }

        askConfirm({
            title: `Permanently delete ${user.full_name}?`,
            description:
                'This cannot be undone. All data associated with this account will be permanently removed.',
            confirmLabel: 'Delete permanently',
            destructive: true,
            run: () =>
                router.delete(userRoutes.forceDelete(user.id), withProcessing),
        });
    };

    // ── Bulk actions ───────────────────────────────────────────────────────
    const runBulk = (action: BulkAction) => {
        router.post(
            userRoutes.bulk,
            { action, ids: selected },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setConfirmOpen(false);
                    setSelected([]);
                },
            },
        );
    };

    const handleBulk = (action: BulkAction) => {
        const count = selected.length;
        const noun = count === 1 ? 'user' : 'users';

        if (action === 'archive') {
            return askConfirm({
                title: `Archive ${count} ${noun}?`,
                description: `${count} ${noun} will be deactivated and moved to the archive.`,
                confirmLabel: 'Archive',
                destructive: true,
                run: () => runBulk('archive'),
            });
        }

        if (action === 'delete') {
            return askConfirm({
                title: `Permanently delete ${count} ${noun}?`,
                description: `This cannot be undone. ${count} ${noun} will be removed for good.`,
                confirmLabel: 'Delete permanently',
                destructive: true,
                run: () => runBulk('delete'),
            });
        }

        runBulk(action);
    };

    // ── Selection ──────────────────────────────────────────────────────────
    const toggleAll = (checked: boolean) =>
        setSelected(checked ? rows.map((user) => user.id) : []);

    const toggleRow = (id: number, checked: boolean) =>
        setSelected((current) =>
            checked
                ? [...current, id]
                : current.filter((value) => value !== id),
        );

    return (
        <>
            <Head title="User Management" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        User Management
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Manage accounts, access and security across your
                        organisation.
                    </p>
                </div>

                <UsersStats stats={stats} />

                <div className="flex flex-col gap-4">
                    <UsersToolbar
                        filters={filters}
                        canCreate={can.create}
                        canExport={can.export}
                        onSearch={setSearch}
                        onStatus={setStatus}
                        onReset={reset}
                        onCreate={openCreate}
                    />

                    {selected.length > 0 && (
                        <BulkActionsBar
                            count={selected.length}
                            scope={filters.status}
                            can={can}
                            onAction={handleBulk}
                            onClear={() => setSelected([])}
                        />
                    )}

                    <UsersTable
                        users={rows}
                        filters={filters}
                        selected={selected}
                        can={can}
                        onToggleSort={toggleSort}
                        onToggleAll={toggleAll}
                        onToggleRow={toggleRow}
                        onView={openView}
                        onEdit={openEdit}
                        onToggleStatus={toggleStatus}
                        onResetPassword={openResetPassword}
                        onResendVerification={resendVerification}
                        onArchive={archive}
                        onRestore={restore}
                        onDelete={remove}
                    />

                    <UsersPagination
                        meta={users.meta}
                        perPage={filters.per_page}
                        onPage={setPage}
                        onPerPage={setPerPage}
                    />
                </div>
            </div>

            <UserFormSheet
                user={formUser}
                assignableRoles={assignableRoles}
                canAssignRoles={can.assignRoles}
                open={formOpen}
                onOpenChange={setFormOpen}
            />

            <UserDetailSheet
                user={detailUser}
                open={detailOpen}
                onOpenChange={setDetailOpen}
                onEdit={openEdit}
                onResendVerification={resendVerification}
            />

            <ResetPasswordDialog
                user={passwordUser}
                open={passwordOpen}
                onOpenChange={setPasswordOpen}
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

UsersIndex.layout = {
    breadcrumbs: [
        {
            title: 'User Management',
            href: '/system/users',
        },
    ],
};
