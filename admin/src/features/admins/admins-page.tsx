import { useQueryClient } from '@tanstack/react-query';
import {
    ShieldAlert,
    ShieldCheck,
    ShieldUser,
    UserPlus,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    DataTable,
    EmptyTableRow,
    FilterSelect,
    ListToolbar,
    PageBody,
    PageHeader,
    SearchInput,
    SortableHead,
    StatTiles,
    TableCard,
    TablePagination,
} from '@/components/data-table';
import {
    LoadError,
    SkeletonRows,
    SkeletonTiles,
} from '@/components/page-states';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useListParams } from '@/hooks/use-list-params';
import { usePageTitle } from '@/hooks/use-page-title';
import { ApiError } from '@/lib/api';
import {
    formatDate,
    formatDateTime,
    formatNumber,
    formatRelative,
} from '@/lib/format';
import type { Admin } from '@/lib/types';
import {
    createAdmin,
    deleteAdmin,
    resetAdminPassword,
    resetAdminTwoFactor,
    setAdminActive,
    updateAdmin,
    useAdmins,
} from './api';
import type { AdminInput } from './api';
import { AdminFormSheet } from './components/admin-form-sheet';
import { AdminRowActions } from './components/admin-row-actions';
import type { AdminAction } from './components/admin-row-actions';
import { TemporaryPasswordDialog } from './components/temporary-password-dialog';
import type { IssuedPassword } from './components/temporary-password-dialog';

const STATUSES = [
    { value: 'all', label: 'All statuses' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Deactivated' },
    { value: 'no_two_factor', label: 'No authenticator yet' },
];

const ROLES = [
    { value: 'all', label: 'All roles' },
    { value: 'super_admin', label: 'Super admins' },
    { value: 'admin', label: 'Admins' },
];

type Confirmation = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive: boolean;
    run: () => Promise<void>;
};

/**
 * The people who run SYNAPSE, for super admins. The server enforces the two
 * rules this screen also explains: nobody acts on their own account here, and
 * there is always an active super admin.
 */
export function AdminsPage() {
    usePageTitle('Admin accounts');

    const queryClient = useQueryClient();
    const list = useListParams({
        sort: 'name',
        direction: 'asc',
        extra: { role: 'all' },
    });
    const { values } = list;
    const admins = useAdmins({ ...values, role: list.extras.role });
    const page = admins.data;
    const stats = page?.stats;

    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Admin | null>(null);
    const [issued, setIssued] = useState<IssuedPassword | null>(null);
    const [confirmation, setConfirmation] = useState<Confirmation | null>(null);
    const [processing, setProcessing] = useState(false);

    const refresh = () =>
        Promise.all([
            queryClient.invalidateQueries({ queryKey: ['admins'] }),
            queryClient.invalidateQueries({ queryKey: ['overview'] }),
            queryClient.invalidateQueries({ queryKey: ['activity'] }),
            queryClient.invalidateQueries({ queryKey: ['me'] }),
        ]);

    const sortProps = (column: string) => ({
        active: values.sort === column,
        direction: values.direction,
        onSort: () => list.toggleSort(column),
    });

    const save = async (input: AdminInput) => {
        if (editing) {
            const result = await updateAdmin(editing.id, input);
            toast.success(result.message);
        } else {
            const result = await createAdmin(input);
            setIssued({
                name: result.admin.name,
                email: result.admin.email,
                password: result.temporary_password,
                reason: 'created',
            });
        }

        setFormOpen(false);
        await refresh();
    };

    const confirmThen = (config: Confirmation) => setConfirmation(config);

    const runConfirmed = async () => {
        if (!confirmation) {
            return;
        }

        setProcessing(true);

        try {
            await confirmation.run();
            setConfirmation(null);
            await refresh();
        } catch (failure) {
            toast.error(
                failure instanceof ApiError
                    ? failure.message
                    : 'That did not work. Try again.',
            );
        } finally {
            setProcessing(false);
        }
    };

    const onAction = (action: AdminAction, admin: Admin) => {
        switch (action) {
            case 'edit':
                setEditing(admin);
                setFormOpen(true);

                return;
            case 'reset-password':
                return confirmThen({
                    title: `Reset ${admin.name}'s password?`,
                    description:
                        'They are signed out everywhere at once. You get a one-time password to pass on; they choose their own at their next sign-in.',
                    confirmLabel: 'Reset password',
                    destructive: false,
                    run: async () => {
                        const result = await resetAdminPassword(admin.id);
                        setIssued({
                            name: admin.name,
                            email: admin.email,
                            password: result.temporary_password,
                            reason: 'reset',
                        });
                    },
                });
            case 'reset-two-factor':
                return confirmThen({
                    title: `Reset ${admin.name}'s authenticator?`,
                    description:
                        'For a lost or replaced phone. They are signed out everywhere, and set up a new authenticator at their next sign-in.',
                    confirmLabel: 'Reset authenticator',
                    destructive: false,
                    run: async () => {
                        toast.success(
                            (await resetAdminTwoFactor(admin.id)).message,
                        );
                    },
                });
            case 'deactivate':
                return confirmThen({
                    title: `Deactivate ${admin.name}?`,
                    description:
                        'They are signed out at once and cannot sign in until a super admin activates the account again.',
                    confirmLabel: 'Deactivate',
                    destructive: true,
                    run: async () => {
                        toast.success(
                            (await setAdminActive(admin.id, false)).message,
                        );
                    },
                });
            case 'activate':
                return confirmThen({
                    title: `Activate ${admin.name}?`,
                    description:
                        'They can sign in again with their password and authenticator.',
                    confirmLabel: 'Activate',
                    destructive: false,
                    run: async () => {
                        toast.success(
                            (await setAdminActive(admin.id, true)).message,
                        );
                    },
                });
            case 'delete':
                return confirmThen({
                    title: `Delete ${admin.name}'s account?`,
                    description:
                        'This cannot be undone. Their entries in the activity log stay, under their name.',
                    confirmLabel: 'Delete account',
                    destructive: true,
                    run: async () => {
                        toast.success((await deleteAdmin(admin.id)).message);
                    },
                });
        }
    };

    return (
        <PageBody>
            <PageHeader
                title="Admin accounts"
                description="Who can sign in to this portal, and what they may do."
                actions={
                    <Button
                        onClick={() => {
                            setEditing(null);
                            setFormOpen(true);
                        }}
                    >
                        <UserPlus className="size-4" />
                        Add admin
                    </Button>
                }
            />

            {stats ? (
                <StatTiles
                    tiles={[
                        {
                            key: 'total',
                            label: 'Admins',
                            value: formatNumber(stats.total),
                            icon: UsersRound,
                            accent: 'indigo',
                        },
                        {
                            key: 'active',
                            label: 'Active',
                            value: formatNumber(stats.active),
                            icon: ShieldCheck,
                            accent: 'emerald',
                        },
                        {
                            key: 'super',
                            label: 'Super admins',
                            value: formatNumber(stats.super_admins),
                            icon: ShieldUser,
                            accent: 'teal',
                        },
                        {
                            key: 'no2fa',
                            label: 'No authenticator yet',
                            value: formatNumber(stats.without_two_factor),
                            icon: ShieldAlert,
                            accent: 'amber',
                            valueClassName:
                                stats.without_two_factor > 0
                                    ? 'text-amber-600 dark:text-amber-400'
                                    : undefined,
                        },
                    ]}
                />
            ) : (
                <SkeletonTiles count={4} />
            )}

            <ListToolbar filtered={list.filtered} onReset={list.reset}>
                <SearchInput
                    value={values.search}
                    onSearch={list.setSearch}
                    placeholder="Search name or email"
                    label="Search admins"
                />
                <FilterSelect
                    value={values.status}
                    onChange={list.setStatus}
                    options={STATUSES}
                    label="Status"
                    className="w-48"
                />
                <FilterSelect
                    value={list.extras.role}
                    onChange={(value) => list.setExtra('role', value)}
                    options={ROLES}
                    label="Role"
                />
            </ListToolbar>

            {admins.isError && !page ? (
                <LoadError
                    message={admins.error.message}
                    onRetry={() => void admins.refetch()}
                />
            ) : (
                <>
                    <TableCard
                        className={
                            admins.isPlaceholderData
                                ? 'opacity-70 transition-opacity'
                                : undefined
                        }
                    >
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <SortableHead {...sortProps('name')}>
                                        Admin
                                    </SortableHead>
                                    <SortableHead {...sortProps('role')}>
                                        Role
                                    </SortableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="hidden lg:table-cell">
                                        Authenticator
                                    </TableHead>
                                    <SortableHead
                                        {...sortProps('last_login_at')}
                                        className="hidden md:table-cell"
                                    >
                                        Last sign-in
                                    </SortableHead>
                                    <SortableHead
                                        {...sortProps('created_at')}
                                        className="hidden xl:table-cell"
                                    >
                                        Added
                                    </SortableHead>
                                    <TableHead className="w-10" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {!page && <SkeletonRows columns={7} />}
                                {page?.data.length === 0 && (
                                    <EmptyTableRow
                                        colSpan={7}
                                        icon={ShieldUser}
                                        title="No admin matches"
                                        description="Try another search, or reset the filters."
                                    />
                                )}
                                {page?.data.map((admin) => (
                                    <TableRow key={admin.id}>
                                        <TableCell className="max-w-72">
                                            <span className="flex items-center gap-2">
                                                <span className="truncate font-medium">
                                                    {admin.name}
                                                </span>
                                                {admin.is_self && (
                                                    <StatusPill tone="teal">
                                                        You
                                                    </StatusPill>
                                                )}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {admin.email}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {admin.role_label}
                                        </TableCell>
                                        <TableCell>
                                            {!admin.is_active ? (
                                                <StatusPill tone="neutral">
                                                    Deactivated
                                                </StatusPill>
                                            ) : admin.must_change_password ? (
                                                <StatusPill tone="sky">
                                                    Password owed
                                                </StatusPill>
                                            ) : (
                                                <StatusPill tone="emerald">
                                                    Active
                                                </StatusPill>
                                            )}
                                        </TableCell>
                                        <TableCell className="hidden lg:table-cell">
                                            {admin.two_factor_enabled ? (
                                                <span className="inline-flex items-center gap-1.5 text-sm text-emerald-600 dark:text-emerald-400">
                                                    <ShieldCheck className="size-4" />
                                                    Set up
                                                </span>
                                            ) : (
                                                <span className="text-sm text-amber-600 dark:text-amber-400">
                                                    At next sign-in
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className="hidden md:table-cell"
                                            title={formatDateTime(
                                                admin.last_login_at,
                                            )}
                                        >
                                            <span className="block text-muted-foreground">
                                                {formatRelative(
                                                    admin.last_login_at,
                                                    'Never',
                                                )}
                                            </span>
                                            {admin.last_login_ip && (
                                                <span className="block font-mono text-[11px] text-muted-foreground/80">
                                                    {admin.last_login_ip}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="hidden text-muted-foreground xl:table-cell">
                                            {formatDate(admin.created_at)}
                                            {admin.created_by && (
                                                <span className="block text-xs">
                                                    by {admin.created_by}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <AdminRowActions
                                                admin={admin}
                                                onAction={onAction}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </DataTable>
                    </TableCard>

                    {page && (
                        <TablePagination
                            meta={page.meta}
                            perPage={page.meta.per_page}
                            onPage={list.setPage}
                            onPerPage={list.setPerPage}
                        />
                    )}
                </>
            )}

            <AdminFormSheet
                open={formOpen}
                admin={editing}
                onOpenChange={setFormOpen}
                onSubmit={save}
            />

            <TemporaryPasswordDialog
                issued={issued}
                onClose={() => setIssued(null)}
            />

            {confirmation && (
                <ConfirmDialog
                    open
                    onOpenChange={(open) =>
                        !open && !processing && setConfirmation(null)
                    }
                    title={confirmation.title}
                    description={confirmation.description}
                    confirmLabel={confirmation.confirmLabel}
                    destructive={confirmation.destructive}
                    processing={processing}
                    onConfirm={() => void runConfirmed()}
                />
            )}
        </PageBody>
    );
}
