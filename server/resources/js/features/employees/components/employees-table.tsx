import { Users2 } from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    rowOpens,
    SortableHead,
    TableCard,
} from '@/components/data-table';
import { Checkbox } from '@/components/ui/checkbox';
import {
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
import { AppAccessBadge } from './app-access-badge';
import { EmployeeAvatar } from './employee-avatar';
import { EmployeeRowActions } from './employee-row-actions';
import { EmployeeStatusBadge } from './employee-status-badge';

type RowHandlers = {
    onView: (employee: ManagedEmployee) => void;
    onEdit: (employee: ManagedEmployee) => void;
    onInvite: (employee: ManagedEmployee) => void;
    onRevokeInvite: (employee: ManagedEmployee) => void;
    onArchive: (employee: ManagedEmployee) => void;
    onRestore: (employee: ManagedEmployee) => void;
    onDelete: (employee: ManagedEmployee) => void;
};

type Props = RowHandlers & {
    employees: ManagedEmployee[];
    filters: EmployeesFilters;
    selected: number[];
    can: EmployeePermissions;
    highlightId?: number | null;
    onToggleSort: (column: string) => void;
    onToggleAll: (checked: boolean) => void;
    onToggleRow: (id: number, checked: boolean) => void;
};

/**
 * The workforce directory. A row opens the employee's record; the checkbox
 * selects it for bulk actions and the menu carries the rest.
 */
export function EmployeesTable({
    employees,
    filters,
    selected,
    can,
    highlightId,
    onToggleSort,
    onToggleAll,
    onToggleRow,
    ...handlers
}: Props) {
    const allSelected =
        employees.length > 0 && selected.length === employees.length;
    const someSelected = selected.length > 0 && !allSelected;

    const sortable = (column: string) => ({
        active: filters.sort === column,
        direction: filters.direction,
        onSort: () => onToggleSort(column),
    });

    return (
        <TableCard>
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-10">
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
                        <SortableHead {...sortable('first_name')}>
                            Employee
                        </SortableHead>
                        <SortableHead {...sortable('employee_no')}>
                            Employee no.
                        </SortableHead>
                        <TableHead>Department</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>App access</TableHead>
                        <SortableHead {...sortable('date_hired')}>
                            Hired
                        </SortableHead>
                        <TableHead className="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {employees.length === 0 && (
                        <EmptyTableRow
                            colSpan={9}
                            icon={Users2}
                            title="No employees found"
                            description="Try adjusting your search or filters, or add a new employee to get started."
                        />
                    )}

                    {employees.map((employee) => {
                        const isSelected = selected.includes(employee.id);
                        const opens = rowOpens(() => handlers.onView(employee));

                        return (
                            <TableRow
                                key={employee.id}
                                {...opens}
                                data-state={isSelected ? 'selected' : undefined}
                                className={cn(
                                    opens.className,
                                    employee.id === highlightId &&
                                        'synapse-row-flash',
                                )}
                            >
                                <TableCell>
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
                                        className="flex max-w-64 items-center gap-2.5 text-left"
                                    >
                                        <EmployeeAvatar
                                            name={employee.full_name}
                                            initials={employee.initials}
                                            photo={employee.photo}
                                        />
                                        <span className="min-w-0">
                                            <span className="block truncate text-sm font-medium hover:text-[#0ABFBF]">
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
                                <TableCell>
                                    <span className="block max-w-48 truncate text-sm">
                                        {employee.department?.name ?? (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </span>
                                    {employee.position && (
                                        <span className="block max-w-48 truncate text-xs text-muted-foreground">
                                            {employee.position.title}
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
                                <TableCell>
                                    <AppAccessBadge
                                        access={employee.app_access}
                                    />
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {employee.date_hired ?? '—'}
                                </TableCell>
                                <TableCell className="text-right">
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
            </DataTable>
        </TableCard>
    );
}
