import { ArrowDown, ArrowUp, ChevronsUpDown, ShieldCheck, Users2 } from 'lucide-react';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { ManagedUser, UsersFilters } from '../types';
import { UserAvatar } from './user-avatar';
import { UserRowActions } from './user-row-actions';
import { UserStatusBadge } from './user-status-badge';

type RowHandlers = {
    onView: (user: ManagedUser) => void;
    onEdit: (user: ManagedUser) => void;
    onToggleStatus: (user: ManagedUser) => void;
    onResetPassword: (user: ManagedUser) => void;
    onArchive: (user: ManagedUser) => void;
    onRestore: (user: ManagedUser) => void;
    onDelete: (user: ManagedUser) => void;
};

type Props = RowHandlers & {
    users: ManagedUser[];
    filters: UsersFilters;
    selected: number[];
    onToggleSort: (column: string) => void;
    onToggleAll: (checked: boolean) => void;
    onToggleRow: (id: number, checked: boolean) => void;
};

export function UsersTable({
    users,
    filters,
    selected,
    onToggleSort,
    onToggleAll,
    onToggleRow,
    ...handlers
}: Props) {
    const allSelected = users.length > 0 && selected.length === users.length;
    const someSelected = selected.length > 0 && !allSelected;

    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <Table>
                <TableHeader className="bg-muted/40">
                    <TableRow className="hover:bg-transparent">
                        <TableHead className="w-10 pl-4">
                            <Checkbox
                                checked={
                                    allSelected
                                        ? true
                                        : someSelected
                                          ? 'indeterminate'
                                          : false
                                }
                                onCheckedChange={(value) => onToggleAll(value === true)}
                                aria-label="Select all"
                            />
                        </TableHead>
                        <SortHeader column="first_name" filters={filters} onSort={onToggleSort}>
                            User
                        </SortHeader>
                        <SortHeader column="employee_id" filters={filters} onSort={onToggleSort}>
                            Employee ID
                        </SortHeader>
                        <TableHead>Phone</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Security</TableHead>
                        <SortHeader column="last_login_at" filters={filters} onSort={onToggleSort}>
                            Last login
                        </SortHeader>
                        <SortHeader column="created_at" filters={filters} onSort={onToggleSort}>
                            Joined
                        </SortHeader>
                        <TableHead className="w-10 pr-4" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {users.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={9} className="py-16">
                                <div className="flex flex-col items-center justify-center gap-2 text-center">
                                    <span className="flex size-12 items-center justify-center rounded-full bg-muted">
                                        <Users2 className="size-6 text-muted-foreground" />
                                    </span>
                                    <p className="text-sm font-medium">No users found</p>
                                    <p className="max-w-xs text-sm text-muted-foreground">
                                        Try adjusting your search or filters, or add a
                                        new user to get started.
                                    </p>
                                </div>
                            </TableCell>
                        </TableRow>
                    )}

                    {users.map((user) => {
                        const isSelected = selected.includes(user.id);

                        return (
                            <TableRow
                                key={user.id}
                                data-state={isSelected ? 'selected' : undefined}
                            >
                                <TableCell className="pl-4">
                                    <Checkbox
                                        checked={isSelected}
                                        onCheckedChange={(value) =>
                                            onToggleRow(user.id, value === true)
                                        }
                                        aria-label={`Select ${user.full_name}`}
                                    />
                                </TableCell>
                                <TableCell>
                                    <button
                                        type="button"
                                        onClick={() => handlers.onView(user)}
                                        className="flex items-center gap-3 text-left"
                                    >
                                        <UserAvatar user={user} />
                                        <span className="min-w-0">
                                            <span className="block truncate font-medium">
                                                {user.full_name}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {user.email}
                                            </span>
                                        </span>
                                    </button>
                                </TableCell>
                                <TableCell>
                                    {user.employee_id ? (
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {user.employee_id}
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">—</span>
                                    )}
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {user.phone_number || '—'}
                                </TableCell>
                                <TableCell>
                                    <UserStatusBadge status={user.status} />
                                </TableCell>
                                <TableCell>
                                    <div className="flex items-center gap-1.5">
                                        <span
                                            className={cn(
                                                'inline-flex items-center gap-1 text-xs',
                                                user.email_verified
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : 'text-muted-foreground',
                                            )}
                                            title={
                                                user.email_verified
                                                    ? 'Email verified'
                                                    : 'Email not verified'
                                            }
                                        >
                                            <ShieldCheck className="size-3.5" />
                                        </span>
                                        {user.two_factor_enabled && (
                                            <span className="rounded bg-[#0ABFBF]/10 px-1.5 py-0.5 text-[10px] font-semibold text-[#0ABFBF]">
                                                2FA
                                            </span>
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {user.last_login_human ?? 'Never'}
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {user.created_human ?? '—'}
                                </TableCell>
                                <TableCell className="pr-4 text-right">
                                    <UserRowActions user={user} {...handlers} />
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}

function SortHeader({
    column,
    filters,
    onSort,
    children,
}: {
    column: string;
    filters: UsersFilters;
    onSort: (column: string) => void;
    children: React.ReactNode;
}) {
    const active = filters.sort === column;

    return (
        <TableHead>
            <button
                type="button"
                onClick={() => onSort(column)}
                className={cn(
                    'inline-flex items-center gap-1 transition-colors hover:text-foreground',
                    active && 'text-foreground',
                )}
            >
                {children}
                {active ? (
                    filters.direction === 'asc' ? (
                        <ArrowUp className="size-3.5" />
                    ) : (
                        <ArrowDown className="size-3.5" />
                    )
                ) : (
                    <ChevronsUpDown className="size-3.5 opacity-40" />
                )}
            </button>
        </TableHead>
    );
}
