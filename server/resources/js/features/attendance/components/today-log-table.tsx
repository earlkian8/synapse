import {
    ArrowDown,
    ArrowUp,
    ChevronsUpDown,
    TriangleAlert,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import {
    Table,
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
import { cn } from '@/lib/utils';
import { formatDuration, formatTime, recordAnomalies } from '../constants';
import type { AttendanceRecord } from '../types';
import { AttendanceStatusBadge } from './attendance-status-badge';

type SortKey = 'name' | 'in' | 'out' | 'hours' | 'status';
type SortDir = 'asc' | 'desc';

const STATUS_ORDER: Record<string, number> = {
    incomplete: 0,
    absent: 1,
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
 * computed hours, a status pill and an anomaly flag. The premium, dense answer
 * to "what happened today, per person".
 */
export function TodayLogTable({
    records,
    onOpen,
}: {
    records: AttendanceRecord[];
    onOpen: (record: AttendanceRecord) => void;
}) {
    const [sort, setSort] = useState<{ key: SortKey; dir: SortDir }>({
        key: 'name',
        dir: 'asc',
    });

    const sorted = useMemo(() => {
        const rows = [...records];
        rows.sort((a, b) => {
            const av = sortValue(a, sort.key);
            const bv = sortValue(b, sort.key);
            const cmp = av < bv ? -1 : av > bv ? 1 : 0;

            return sort.dir === 'asc' ? cmp : -cmp;
        });

        return rows;
    }, [records, sort]);

    const toggle = (key: SortKey) =>
        setSort((prev) =>
            prev.key === key
                ? { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' }
                : { key, dir: key === 'name' ? 'asc' : 'desc' },
        );

    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
            <Table>
                <TableHeader>
                    <TableRow className="hover:bg-transparent">
                        <SortHeader
                            label="Employee"
                            active={sort}
                            col="name"
                            onSort={toggle}
                        />
                        <SortHeader
                            label="Time in"
                            active={sort}
                            col="in"
                            onSort={toggle}
                            align="right"
                            className="hidden sm:table-cell"
                        />
                        <SortHeader
                            label="Time out"
                            active={sort}
                            col="out"
                            onSort={toggle}
                            align="right"
                            className="hidden sm:table-cell"
                        />
                        <SortHeader
                            label="Hours"
                            active={sort}
                            col="hours"
                            onSort={toggle}
                            align="right"
                        />
                        <SortHeader
                            label="Status"
                            active={sort}
                            col="status"
                            onSort={toggle}
                        />
                        <TableHead className="w-10 text-center">
                            <span className="sr-only">Flags</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {sorted.map((record) => (
                        <Row
                            key={record.employee?.id ?? record.id}
                            record={record}
                            onOpen={onOpen}
                        />
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function Row({
    record,
    onOpen,
}: {
    record: AttendanceRecord;
    onOpen: (record: AttendanceRecord) => void;
}) {
    const employee = record.employee;
    const anomalies = recordAnomalies(record);
    const worked = record.worked_minutes > 0;

    return (
        <TableRow
            className="cursor-pointer"
            onClick={() => onOpen(record)}
            tabIndex={0}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onOpen(record);
                }
            }}
        >
            <TableCell className="py-2.5">
                <div className="flex items-center gap-3">
                    <PersonAvatar
                        name={employee?.full_name ?? 'Unknown'}
                        initials={employee?.initials ?? '?'}
                        photo={employee?.photo}
                        className="size-9"
                    />
                    <div className="min-w-0">
                        <p className="truncate text-sm font-medium">
                            {employee?.full_name ?? 'Unknown employee'}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {employee?.department?.name ?? '—'}
                        </p>
                    </div>
                </div>
            </TableCell>

            <TableCell className="hidden text-right text-sm tabular-nums sm:table-cell">
                {formatTime(record.first_in_at)}
            </TableCell>
            <TableCell className="hidden text-right text-sm tabular-nums sm:table-cell">
                {formatTime(record.last_out_at)}
            </TableCell>

            <TableCell className="text-right text-sm font-medium tabular-nums">
                {worked ? formatDuration(record.worked_minutes) : '—'}
                {record.overtime_minutes > 0 && (
                    <span className="ml-1 text-xs font-normal text-indigo-600 dark:text-indigo-400">
                        +{formatDuration(record.overtime_minutes)}
                    </span>
                )}
            </TableCell>

            <TableCell>
                <AttendanceStatusBadge status={record.status} />
            </TableCell>

            <TableCell className="text-center">
                {anomalies.length > 0 && (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span
                                className={cn(
                                    'inline-flex size-6 items-center justify-center rounded-md',
                                    anomalies.some((a) => a.tone === 'danger')
                                        ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400'
                                        : 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                                )}
                            >
                                <TriangleAlert className="size-3.5" />
                            </span>
                        </TooltipTrigger>
                        <TooltipContent side="left">
                            <ul className="space-y-0.5">
                                {anomalies.map((a) => (
                                    <li key={a.label}>{a.label}</li>
                                ))}
                            </ul>
                        </TooltipContent>
                    </Tooltip>
                )}
            </TableCell>
        </TableRow>
    );
}

function SortHeader({
    label,
    col,
    active,
    onSort,
    align = 'left',
    className,
}: {
    label: string;
    col: SortKey;
    active: { key: SortKey; dir: SortDir };
    onSort: (key: SortKey) => void;
    align?: 'left' | 'right';
    className?: string;
}) {
    const isActive = active.key === col;
    const Icon = !isActive
        ? ChevronsUpDown
        : active.dir === 'asc'
          ? ArrowUp
          : ArrowDown;

    return (
        <TableHead className={className}>
            <button
                type="button"
                onClick={() => onSort(col)}
                className={cn(
                    'inline-flex items-center gap-1 transition-colors hover:text-foreground',
                    align === 'right' && 'flex-row-reverse',
                    isActive && 'text-foreground',
                )}
            >
                {label}
                <Icon
                    className={cn(
                        'size-3',
                        isActive ? 'opacity-100' : 'opacity-40',
                    )}
                />
            </button>
        </TableHead>
    );
}
