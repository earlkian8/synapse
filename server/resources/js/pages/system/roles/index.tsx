import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/features/roles/components/confirm-dialog';
import { RoleBulkActionsBar } from '@/features/roles/components/role-bulk-actions-bar';
import { RoleDetailSheet } from '@/features/roles/components/role-detail-sheet';
import { RoleFormSheet } from '@/features/roles/components/role-form-sheet';
import { RolesPagination } from '@/features/roles/components/roles-pagination';
import { RolesStats } from '@/features/roles/components/roles-stats';
import { RolesTable } from '@/features/roles/components/roles-table';
import { RolesToolbar } from '@/features/roles/components/roles-toolbar';
import { useRolesFilters } from '@/features/roles/hooks/use-roles-filters';
import { roleRoutes } from '@/features/roles/routes';
import type {
    BulkRoleAction,
    ManagedRole,
    RolesPageProps,
} from '@/features/roles/types';
import { usePermissions } from '@/hooks/use-permissions';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

export default function RolesIndex() {
    const { roles, stats, permissionGroups, filters } =
        usePage<RolesPageProps>().props;
    const { can } = usePermissions();
    const { setSearch, setType, setPerPage, setPage, toggleSort, reset } =
        useRolesFilters(filters);

    const canCreate = can('roles.create');
    const canUpdate = can('roles.update');
    const canDelete = can('roles.delete');

    const [selected, setSelected] = useState<number[]>([]);

    // Drop stale selections whenever the underlying result set changes.
    const signature = useMemo(
        () =>
            [
                filters.search,
                filters.type,
                filters.sort,
                filters.direction,
                filters.per_page,
                roles.meta.current_page,
            ].join('|'),
        [filters, roles.meta.current_page],
    );
    const [selectionScope, setSelectionScope] = useState(signature);

    if (signature !== selectionScope) {
        setSelectionScope(signature);
        setSelected([]);
    }

    const toggleAll = (checked: boolean) =>
        setSelected(checked ? roles.data.map((r) => r.id) : []);

    const toggleRow = (id: number, checked: boolean) =>
        setSelected((prev) =>
            checked ? [...prev, id] : prev.filter((x) => x !== id),
        );

    // Built-in roles are protected server-side; reflect that in the bulk bar.
    const deletableCount = useMemo(
        () =>
            roles.data.filter((r) => selected.includes(r.id) && !r.is_system)
                .length,
        [roles.data, selected],
    );

    const [formRole, setFormRole] = useState<ManagedRole | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [detailRole, setDetailRole] = useState<ManagedRole | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const openCreate = () => {
        setFormRole(null);
        setFormOpen(true);
    };

    const openEdit = (role: ManagedRole) => {
        setDetailOpen(false);
        setFormRole(role);
        setFormOpen(true);
    };

    const openView = (role: ManagedRole) => {
        setDetailRole(role);
        setDetailOpen(true);
    };

    const askConfirm = (config: ConfirmConfig) => {
        setConfirm(config);
        setConfirmOpen(true);
    };

    const remove = (role: ManagedRole) =>
        askConfirm({
            title: `Delete ${role.label}?`,
            description: role.users_count
                ? `${role.users_count} ${
                      role.users_count === 1 ? 'member' : 'members'
                  } will lose this role. This cannot be undone.`
                : 'This role will be permanently removed. This cannot be undone.',
            confirmLabel: 'Delete role',
            run: () =>
                router.delete(roleRoutes.destroy(role.id), {
                    preserveScroll: true,
                    onStart: () => setProcessing(true),
                    onFinish: () => {
                        setProcessing(false);
                        setConfirmOpen(false);
                    },
                }),
        });

    const runBulk = (action: BulkRoleAction) => {
        router.post(
            roleRoutes.bulk,
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

    const handleBulk = (action: BulkRoleAction) => {
        if (action !== 'delete') {
            return;
        }

        const protectedCount = selected.length - deletableCount;

        // Everything selected is a built-in role — there's nothing to delete, so
        // give immediate feedback instead of opening an empty confirm dialog.
        if (deletableCount === 0) {
            toast.warning(
                protectedCount === 1
                    ? 'That role is built-in and cannot be deleted.'
                    : 'Those roles are built-in and cannot be deleted.',
            );

            return;
        }

        const noun = deletableCount === 1 ? 'role' : 'roles';
        const skipNote =
            protectedCount > 0
                ? ` ${protectedCount} built-in ${
                      protectedCount === 1 ? 'role' : 'roles'
                  } in your selection will be skipped.`
                : '';

        askConfirm({
            title: `Delete ${deletableCount} ${noun}?`,
            description: `This cannot be undone. ${deletableCount} custom ${noun} will be permanently removed and any members will lose them.${skipNote}`,
            confirmLabel: 'Delete roles',
            run: () => runBulk('delete'),
        });
    };

    return (
        <>
            <Head title="Roles & Permissions" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Roles &amp; Permissions
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Define what each role can do and who holds it across the
                        system.
                    </p>
                </div>

                <RolesStats stats={stats} />

                <div className="flex flex-col gap-4">
                    <RolesToolbar
                        filters={filters}
                        canCreate={canCreate}
                        onSearch={setSearch}
                        onType={setType}
                        onReset={reset}
                        onCreate={openCreate}
                    />

                    {selected.length > 0 && (
                        <RoleBulkActionsBar
                            count={selected.length}
                            deletableCount={deletableCount}
                            canDelete={canDelete}
                            onAction={handleBulk}
                            onClear={() => setSelected([])}
                        />
                    )}

                    <RolesTable
                        roles={roles.data}
                        filters={filters}
                        selected={selected}
                        canUpdate={canUpdate}
                        canDelete={canDelete}
                        onToggleSort={toggleSort}
                        onToggleAll={toggleAll}
                        onToggleRow={toggleRow}
                        onView={openView}
                        onEdit={openEdit}
                        onDelete={remove}
                    />

                    <RolesPagination
                        meta={roles.meta}
                        perPage={filters.per_page}
                        onPage={setPage}
                        onPerPage={setPerPage}
                    />
                </div>
            </div>

            <RoleFormSheet
                role={formRole}
                groups={permissionGroups}
                open={formOpen}
                onOpenChange={setFormOpen}
            />

            <RoleDetailSheet
                role={detailRole}
                groups={permissionGroups}
                open={detailOpen}
                canEdit={canUpdate}
                onOpenChange={setDetailOpen}
                onEdit={openEdit}
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

RolesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Roles & Permissions',
            href: '/system/roles',
        },
    ],
};
