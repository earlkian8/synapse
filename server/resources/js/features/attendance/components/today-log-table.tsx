import { CalendarCheck, Eye, Pencil, TriangleAlert } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    DataTable,
    EmptyTableRow,
    RowMenuTrigger,
    rowOpens,
    SortableHead,
    TableCard,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';
import { cn } from '@/lib/utils';
import { formatDuration, formatTime, recordAnomalies } from '../constants';
import type { AttendanceRecord } from '../types';
import { AttendanceStatusBadge } from './attendance-status-badge';

type SortKey = 'name' | 'in' | 'out' | 'hours' | 'status';

const STATUS_ORDER: Record<string, number> = {
    incomplete: 0,
    absent: 1,
    half_day: 1.5,
    late: 2,
    undertime: 3,
    present: 4,
    on_leave: 5,
    day_off: 6,
    holiday: 7,
};

/** Comparable key per row for the active sort column. */
function sortValue(record: AttendanceRecord, key: SortKey): string | number {
    switch (key) {
        case 'name':
            return record.employee?.full_name?.toLowerCase() ?? '';
        case 'in':
            return record.first_in_at
                ? Date.parse(record.first_in_at)
                : Infinity;
        case 'out':
            return record.last_out_at
                ? Date.parse(record.last_out_at)
                : Infinity;
        case 'hours':
            return record.worked_minutes;
        case 'status':
            return STATUS_ORDER[record.status] ?? 99;
    }
}

/**
 * The daily log — one sortable row per employee with their in / out times,
 * computed hours, a status pill and an anomaly flag. A row opens that
 * person's day.
 */
export function TodayLogTable({
    records,
    canManage,
    onOpen,
    onEdit,
    resetKey,
}: {
    records: AttendanceRecord[];
    /** The server filters on screen — changing them returns to page one. */
    resetKey: string;
    canManage: boolean;
    onOpen: (record: AttendanceRecord) => void;
    onEdit: (record: AttendanceRecord) => void;
}) {
    const timeZone = useOrganizationTimeZone();
    const [sort, setSort] = useState<SortKey>('name');
    const [direction, setDirection] = useState<'asc' | 'desc'>('asc');

    const sorted = useMemo(() => {
        const dir = direction === 'asc' ? 1 : -1;

        return [...records].sort((a, b) => {
            const av = sortValue(a, sort);
            const bv = sortValue(b, sort);

            return (av < bv ? -1 : av > bv ? 1 : 0) * dir;
        });
    }, [records, sort, direction]);

    const page = useClientPagination(
        sorted,
        `${resetKey}|${sort}|${direction}`,
        25,
    );

    const sortable = (key: SortKey) => ({
        active: sort === key,
        direction,
        onSort: () => {
            if (key === sort) {
                setDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
            } else {
                setSort(key);
                setDirection(key === 'name' ? 'asc' : 'desc');
            }
        },
    });

    return (
        <div className="flex flex-col gap-3">
            <TableCard title="Daily log" count={records.length}>
                <DataTable>
                    <TableHeader>
                        <TableRow>
                            <SortableHead {...sortable('name')}>
                                Employee
                            </SortableHead>
                            <SortableHead
                                {...sortable('in')}
                                align="right"
                                className="hidden sm:table-cell"
                            >
                                Time in
                            </SortableHead>
                            <SortableHead
                                {...sortable('out')}
                                align="right"
                                className="hidden sm:table-cell"
                            >
                                Time out
                            </SortableHead>
                            <SortableHead {...sortable('hours')} align="right">
                                Hours
                            </SortableHead>
                            <SortableHead {...sortable('status')}>
                                Status
                            </SortableHead>
                            <TableHead className="w-10 text-center">
                                <span className="sr-only">Flags</span>
                            </TableHead>
                            <TableHead className="w-10" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {records.length === 0 && (
                            <EmptyTableRow
                                colSpan={7}
                                icon={CalendarCheck}
                                title="No employees match this view"
                                description="Try a different date, status, or department filter."
                            />
                        )}

                        {page.rows.map((record) => {
                            const employee = record.employee;
                            const name = employee?.full_name ?? 'Unknown';
                            const anomalies = recordAnomalies(record);
                            const opens = rowOpens(() => onOpen(record));

                            return (
                                <TableRow
                                    key={employee?.id ?? record.id}
                                    {...opens}
                                >
                                    <TableCell>
                                        <div className="flex min-w-0 items-center gap-2.5">
                                            <PersonAvatar
                                                name={name}
                                                initials={
                                                    employee?.initials ?? '?'
                                                }
                                                photo={employee?.photo}
                                                className="size-8"
                                                fallbackClassName="text-[11px]"
                                            />
                                            <div className="min-w-0">
                                                <p className="max-w-60 truncate text-sm font-medium">
                                                    {name}
                                                </p>
                                                <p className="max-w-60 truncate text-xs text-muted-foreground">
                                                    {employee?.department
                                                        ?.name ?? '—'}
                                                </p>
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell className="hidden text-right text-sm tabular-nums sm:table-cell">
                                        {formatTime(
                                            record.first_in_at,
                                            timeZone,
                                        )}
                                    </TableCell>
                                    <TableCell className="hidden text-right text-sm tabular-nums sm:table-cell">
                                        {formatTime(
                                            record.last_out_at,
                                            timeZone,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right text-sm font-medium tabular-nums">
                                        {record.worked_minutes > 0
                                            ? formatDuration(
                                                  record.worked_minutes,
                                              )
                                            : '—'}
                                        {record.overtime_minutes > 0 && (
                                            <span className="ml-1 text-xs font-normal text-indigo-600 dark:text-indigo-400">
                                                +
                                                {formatDuration(
                                                    record.overtime_minutes,
                                                )}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <AttendanceStatusBadge
                                            status={record.status}
                                        />
                                    </TableCell>
                                    <TableCell className="text-center">
                                        {anomalies.length > 0 && (
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <span
                                                        className={cn(
                                                            'inline-flex size-6 items-center justify-center rounded-md',
                                                            anomalies.some(
                                                                (a) =>
                                                                    a.tone ===
                                                                    'danger',
                                                            )
                                                                ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400'
                                                                : 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                                                        )}
                                                        aria-label={anomalies
                                                            .map((a) => a.label)
                                                            .join(', ')}
                                                    >
                                                        <TriangleAlert className="size-3.5" />
                                                    </span>
                                                </TooltipTrigger>
                                                <TooltipContent side="left">
                                                    <ul className="space-y-0.5">
                                                        {anomalies.map((a) => (
                                                            <li key={a.label}>
                                                                {a.label}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </TooltipContent>
                                            </Tooltip>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <RowMenuTrigger
                                                    label={`Actions for ${name}`}
                                                />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent
                                                align="end"
                                                className="w-44"
                                            >
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        onOpen(record)
                                                    }
                                                >
                                                    <Eye className="size-4" />
                                                    Open the day
                                                </DropdownMenuItem>
                                                {canManage && (
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            onEdit(record)
                                                        }
                                                    >
                                                        <Pencil className="size-4" />
                                                        {record.hashid
                                                            ? 'Edit punches'
                                                            : 'Add punches'}
                                                    </DropdownMenuItem>
                                                )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </DataTable>
            </TableCard>

            <TablePagination
                meta={page.meta}
                perPage={page.perPage}
                onPage={page.setPage}
                onPerPage={page.setPerPage}
            />
        </div>
    );
}
