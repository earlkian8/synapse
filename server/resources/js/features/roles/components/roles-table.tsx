import {
    ArrowDown,
    ArrowUp,
    ChevronsUpDown,
    KeyRound,
    ShieldCheck,
    Users,
} from 'lucide-react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { ManagedRole, RolesFilters } from '../types';
import { RoleBadge } from './role-badge';
import { RoleRowActions } from './role-row-actions';

type RowHandlers = {
    onView: (role: ManagedRole) => void;
    onEdit: (role: ManagedRole) => void;
    onDelete: (role: ManagedRole) => void;
};

type Props = RowHandlers & {
    roles: ManagedRole[];
    filters: RolesFilters;
    canUpdate: boolean;
    canDelete: boolean;
    onToggleSort: (column: string) => void;
};

export function RolesTable({
    roles,
    filters,
    canUpdate,
    canDelete,
    onToggleSort,
    ...handlers
}: Props) {
    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <Table>
                <TableHeader className="bg-muted/40">
                    <TableRow className="hover:bg-transparent">
                        <SortHeader
                            column="label"
                            filters={filters}
                            onSort={onToggleSort}
                        >
                            Role
                        </SortHeader>
                        <TableHead>Type</TableHead>
                        <TableHead>Permissions</TableHead>
                        <TableHead>Members</TableHead>
                        <SortHeader
                            column="created_at"
                            filters={filters}
                            onSort={onToggleSort}
                        >
                            Created
                        </SortHeader>
                        <TableHead className="w-10 pr-4" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {roles.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={6} className="py-16">
                                <div className="flex flex-col items-center justify-center gap-2 text-center">
                                    <span className="flex size-12 items-center justify-center rounded-full bg-muted">
                                        <ShieldCheck className="size-6 text-muted-foreground" />
                                    </span>
                                    <p className="text-sm font-medium">
                                        No roles found
                                    </p>
                                    <p className="max-w-xs text-sm text-muted-foreground">
                                        Try adjusting your search or filters, or
                                        create a new role to get started.
                                    </p>
                                </div>
                            </TableCell>
                        </TableRow>
                    )}

                    {roles.map((role) => (
                        <TableRow key={role.id}>
                            <TableCell>
                                <button
                                    type="button"
                                    onClick={() => handlers.onView(role)}
                                    className="flex items-start gap-3 text-left"
                                >
                                    <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0F2044] text-white">
                                        <ShieldCheck className="size-4" />
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block truncate font-medium">
                                            {role.label}
                                        </span>
                                        <span className="block truncate font-mono text-xs text-muted-foreground">
                                            {role.name}
                                        </span>
                                    </span>
                                </button>
                            </TableCell>
                            <TableCell>
                                <RoleBadge role={role} />
                            </TableCell>
                            <TableCell>
                                <span className="inline-flex items-center gap-1.5 text-sm">
                                    <KeyRound className="size-3.5 text-muted-foreground" />
                                    <span className="font-medium tabular-nums">
                                        {role.is_super_admin
                                            ? 'All'
                                            : role.permissions_count}
                                    </span>
                                </span>
                            </TableCell>
                            <TableCell>
                                <span className="inline-flex items-center gap-1.5 text-sm">
                                    <Users className="size-3.5 text-muted-foreground" />
                                    <span className="font-medium tabular-nums">
                                        {role.users_count}
                                    </span>
                                </span>
                            </TableCell>
                            <TableCell className="text-sm text-muted-foreground">
                                {role.created_human ?? '—'}
                            </TableCell>
                            <TableCell className="pr-4 text-right">
                                <RoleRowActions
                                    role={role}
                                    canUpdate={canUpdate}
                                    canDelete={canDelete}
                                    {...handlers}
                                />
                            </TableCell>
                        </TableRow>
                    ))}
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
    filters: RolesFilters;
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
