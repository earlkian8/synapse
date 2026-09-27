import { SlidersHorizontal, Users } from 'lucide-react';
import { DataTable, EmptyTableRow, TableCard } from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { BalanceSnapshot, BalanceType, EmployeeBalance } from '../types';

type Props = {
    employees: EmployeeBalance[];
    types: BalanceType[];
    year: number;
    canManage: boolean;
    filtered: boolean;
    onAdjust: (employee: EmployeeBalance) => void;
};

/**
 * Every employee's balances for the year, one column per leave type: what is
 * left against what they are entitled to, with a meter whose track is the leave
 * type's own colour, lighter.
 */
export function BalancesTable({
    employees,
    types,
    year,
    canManage,
    filtered,
    onAdjust,
}: Props) {
    const columns = types.length + (canManage ? 2 : 1);

    return (
        <TableCard>
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <TableHead>Employee</TableHead>
                        {types.map((type) => (
                            <TableHead
                                key={type.id}
                                className="min-w-24"
                                title={`${type.name} — days left of the ${year} entitlement`}
                            >
                                <span className="inline-flex items-center gap-1.5">
                                    <span
                                        className="size-1.5 rounded-full"
                                        style={{ backgroundColor: type.color }}
                                        aria-hidden="true"
                                    />
                                    {type.code}
                                </span>
                            </TableHead>
                        ))}
                        {canManage && <TableHead className="w-10" />}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {employees.length === 0 && (
                        <EmptyTableRow
                            colSpan={columns}
                            icon={Users}
                            title="No employees"
                            description={
                                filtered
                                    ? 'No employees match this search or department.'
                                    : 'No employees are on the roster yet.'
                            }
                        />
                    )}

                    {employees.map((employee) => (
                        <TableRow key={employee.id}>
                            <TableCell>
                                <div className="flex min-w-0 items-center gap-2.5">
                                    <PersonAvatar
                                        name={employee.full_name}
                                        initials={employee.initials}
                                        photo={employee.photo}
                                        className="size-8"
                                        fallbackClassName="text-[11px]"
                                    />
                                    <div className="min-w-0">
                                        <p className="max-w-52 truncate text-sm font-medium">
                                            {employee.full_name}
                                        </p>
                                        <p className="max-w-52 truncate text-xs text-muted-foreground">
                                            {employee.department ??
                                                'No department'}
                                        </p>
                                    </div>
                                </div>
                            </TableCell>
                            {types.map((type) => (
                                <TableCell key={type.id}>
                                    <Balance
                                        balance={employee.balances.find(
                                            (b) => b.leave_type_id === type.id,
                                        )}
                                    />
                                </TableCell>
                            ))}
                            {canManage && (
                                <TableCell className="text-right">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="h-8"
                                        onClick={() => onAdjust(employee)}
                                    >
                                        <SlidersHorizontal className="size-3.5" />
                                        Adjust
                                    </Button>
                                </TableCell>
                            )}
                        </TableRow>
                    ))}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}

function Balance({ balance }: { balance: BalanceSnapshot | undefined }) {
    if (!balance) {
        return <span className="text-sm text-muted-foreground">—</span>;
    }

    const exhausted = balance.remaining <= 0 && balance.entitled > 0;
    const share =
        balance.entitled > 0
            ? Math.max(
                  0,
                  Math.min(100, (balance.remaining / balance.entitled) * 100),
              )
            : 0;

    return (
        <div
            className="flex w-20 flex-col gap-1"
            title={`${balance.name}: ${balance.remaining} left of ${balance.entitled} · ${balance.used} used · ${balance.pending} pending${balance.is_custom ? ' · adjusted' : ''}`}
        >
            <span className="text-sm tabular-nums">
                <span
                    className={cn(
                        'font-semibold',
                        exhausted && 'text-rose-600 dark:text-rose-400',
                    )}
                >
                    {balance.remaining}
                </span>
                <span className="text-xs text-muted-foreground">
                    {' '}
                    / {balance.entitled}
                </span>
            </span>
            <span
                className="h-1 w-full overflow-hidden rounded-full"
                style={{ backgroundColor: `${balance.color}26` }}
                aria-hidden="true"
            >
                <span
                    className="block h-full rounded-full"
                    style={{
                        width: `${share}%`,
                        backgroundColor: balance.color,
                    }}
                />
            </span>
        </div>
    );
}
