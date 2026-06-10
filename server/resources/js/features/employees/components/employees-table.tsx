import { ArrowDown, ArrowUp, ChevronsUpDown, Users2 } from 'lucide-react';
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
import { TYPE_LABELS } from '../constants';
import type {
    EmployeePermissions,
    EmployeesFilters,
    ManagedEmployee,
} from '../types';
import { EmployeeAvatar } from './employee-avatar';
import { EmployeeRowActions } from './employee-row-actions';
import { EmployeeStatusBadge } from './employee-status-badge';

type RowHandlers = {
    onView: (employee: ManagedEmployee) => void;
    onEdit: (employee: ManagedEmployee) => void;
    onArchive: (employee: ManagedEmployee) => void;
    onRestore: (employee: ManagedEmployee) => void;
    onDelete: (employee: ManagedEmployee) => void;
};

type Props = RowHandlers & {
    employees: ManagedEmployee[];
    filters: EmployeesFilters;
    selected: number[];
    can: EmployeePermissions;
    onToggleSort: (column: string) => void;
    onToggleAll: (checked: boolean) => void;
    onToggleRow: (id: number, checked: boolean) => void;
};

export function EmployeesTable({
    employees,
    filters,
    selected,
    can,
    onToggleSort,
    onToggleAll,
    onToggleRow,
    ...handlers
}: Props) {
    const allSelected =
        employees.length > 0 && selected.length === employees.length;
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
                                onCheckedChange={(value) =>
                                    onToggleAll(value === true)
                                }
                                aria-label="Select all"
                            />
                        </TableHead>
                        <SortHeader
                            column="first_name"
                            filters={filters}
                            onSort={onToggleSort}
                        >
                            Employee
                        </SortHeader>
                        <SortHeader
                            column="employee_no"
                            filters={filters}
                            onSort={onToggleSort}
                        >
                            Employee No.
                        </SortHeader>
                        <TableHead>Department</TableHead>
                        <TableHead>Position</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Status</TableHead>
                        <SortHeader
                            column="date_hired"
                            filters={filters}
                            onSort={onToggleSort}
                        >
                            Hired
                        </SortHeader>
                        <TableHead className="w-10 pr-4" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {employees.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={9} className="py-16">
                                <div className="flex flex-col items-center justify-center gap-2 text-center">
                                    <span className="flex size-12 items-center justify-center rounded-full bg-muted">
                                        <Users2 className="size-6 text-muted-foreground" />
                                    </span>
                                    <p className="text-sm font-medium">
                                        No employees found
                                    </p>
                                    <p className="max-w-xs text-sm text-muted-foreground">
                                        Try adjusting your search or filters, or
                                        add a new employee to get started.
                                    </p>
                                </div>
                            </TableCell>
                        </TableRow>
                    )}

                    {employees.map((employee) => {
                        const isSelected = selected.includes(employee.id);

                        return (
                            <TableRow
                                key={employee.id}
                                data-state={isSelected ? 'selected' : undefined}
                            >
                                <TableCell className="pl-4">
                                    <Checkbox
                                        checked={isSelected}
                                        onCheckedChange={(value) =>
                                            onToggleRow(
                                                employee.id,
                                                value === true,
                                            )
                                        }
                                        aria-label={`Select ${employee.full_name}`}
                                    />
                                </TableCell>
                                <TableCell>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            handlers.onView(employee)
                                        }
                                        className="flex items-center gap-3 text-left"
                                    >
                                        <EmployeeAvatar
                                            name={employee.full_name}
                                            initials={employee.initials}
                                            photo={employee.photo}
                                        />
                                        <span className="min-w-0">
                                            <span className="block truncate font-medium">
                                                {employee.full_name}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {employee.email ?? '—'}
                                            </span>
                                        </span>
                                    </button>
                                </TableCell>
                                <TableCell>
                                    <span className="font-mono text-xs text-muted-foreground">
                                        {employee.employee_no}
                                    </span>
                                </TableCell>
                                <TableCell className="text-sm">
                                    {employee.department?.name ?? (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-sm">
                                    {employee.position?.title ?? (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {TYPE_LABELS[employee.employment_type]}
                                </TableCell>
                                <TableCell>
                                    <EmployeeStatusBadge
                                        status={employee.status}
                                    />
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {employee.date_hired ?? '—'}
                                </TableCell>
                                <TableCell className="pr-4 text-right">
                                    <EmployeeRowActions
                                        employee={employee}
                                        can={can}
                                        {...handlers}
                                    />
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
    filters: EmployeesFilters;
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
