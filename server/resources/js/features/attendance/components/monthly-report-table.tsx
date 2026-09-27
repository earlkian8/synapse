import { LineChart } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    DataTable,
    EmptyTableRow,
    SortableHead,
    TableCard,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { MonthlyReport, MonthlyRow } from '../types';
import { Sparkline } from './sparkline';

/** Colour the attendance rate by band — strong, fair, poor. */
function rateTone(rate: number): string {
    if (rate >= 95) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (rate >= 85) {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-rose-600 dark:text-rose-400';
}

function rateBar(rate: number): string {
    if (rate >= 95) {
        return 'bg-emerald-500';
    }

    if (rate >= 85) {
        return 'bg-amber-500';
    }

    return 'bg-rose-500';
}

/** Minutes as hours to one decimal, the way the report's columns read. */
function hours(minutes: number): string {
    return `${Math.round((minutes / 60) * 10) / 10}h`;
}

type ReportSort =
    | 'name'
    | 'present'
    | 'late'
    | 'half'
    | 'absent'
    | 'holidays'
    | 'overtime'
    | 'rate';

/** Comparable key per row for the active sort column. */
function sortValue(row: MonthlyRow, key: ReportSort): string | number {
    switch (key) {
        case 'name':
            return row.employee.full_name.toLowerCase();
        case 'present':
            return row.present_days;
        case 'late':
            return row.late_count;
        case 'half':
            return row.half_day_count;
        case 'absent':
            return row.absent_count;
        case 'holidays':
            return row.holiday_count;
        case 'overtime':
            return row.overtime_hours;
        case 'rate':
            return row.attendance_rate ?? -1;
    }
}

/**
 * The monthly report — one summary row per employee: present days, late count,
 * half days, absences, overtime (and how much of it is approved), night work
 * when the company's policy sets it apart, and attendance rate, with an inline
 * sparkline of the worked-hours rhythm across the month.
 */
export function MonthlyReportTable({
    report,
    resetKey,
}: {
    report: MonthlyReport;
    /** The server filters on screen — changing them returns to page one. */
    resetKey: string;
}) {
    const [sort, setSort] = useState<ReportSort>('name');
    const [direction, setDirection] = useState<'asc' | 'desc'>('asc');

    const sorted = useMemo(() => {
        const dir = direction === 'asc' ? 1 : -1;

        return [...report.rows].sort((a, b) => {
            const av = sortValue(a, sort);
            const bv = sortValue(b, sort);

            return (av < bv ? -1 : av > bv ? 1 : 0) * dir;
        });
    }, [report.rows, sort, direction]);

    const page = useClientPagination(
        sorted,
        `${resetKey}|${sort}|${direction}`,
        25,
    );

    const sortable = (key: ReportSort) => ({
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

    // Night minutes only exist under a policy that counts them; a column of
    // dashes for everybody else would be noise.
    const showNight = report.rows.some((row) => row.minutes.night > 0);

    return (
        <div className="flex flex-col gap-3">
            <TableCard title="Monthly report" count={report.rows.length}>
                <DataTable>
                    <TableHeader>
                        <TableRow>
                            <SortableHead {...sortable('name')}>
                                Employee
                            </SortableHead>
                            <SortableHead
                                {...sortable('present')}
                                align="right"
                            >
                                Present
                            </SortableHead>
                            <SortableHead {...sortable('late')} align="right">
                                Late
                            </SortableHead>
                            <SortableHead
                                {...sortable('half')}
                                align="right"
                                className="hidden md:table-cell"
                            >
                                Half days
                            </SortableHead>
                            <SortableHead {...sortable('absent')} align="right">
                                Absent
                            </SortableHead>
                            <SortableHead
                                {...sortable('holidays')}
                                align="right"
                                className="hidden md:table-cell"
                            >
                                Holidays
                            </SortableHead>
                            <SortableHead
                                {...sortable('overtime')}
                                align="right"
                                className="hidden md:table-cell"
                            >
                                Overtime
                            </SortableHead>
                            {showNight && (
                                <TableHead className="hidden text-right xl:table-cell">
                                    Night
                                </TableHead>
                            )}
                            <SortableHead {...sortable('rate')} align="right">
                                Rate
                            </SortableHead>
                            <TableHead className="hidden text-right lg:table-cell">
                                Trend
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {report.rows.length === 0 && (
                            <EmptyTableRow
                                colSpan={showNight ? 10 : 9}
                                icon={LineChart}
                                title="No employees match this view"
                                description="Try a different department or search."
                            />
                        )}

                        {page.rows.map((row) => (
                            <Row
                                key={row.employee.id}
                                row={row}
                                showNight={showNight}
                            />
                        ))}
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

function Row({ row, showNight }: { row: MonthlyRow; showNight: boolean }) {
    const rate = row.attendance_rate;
    const awaiting = row.minutes.overtime - row.minutes.approved_overtime;

    return (
        <TableRow>
            <TableCell>
                <div className="flex min-w-0 items-center gap-2.5">
                    <PersonAvatar
                        name={row.employee.full_name}
                        initials={row.employee.initials}
                        photo={row.employee.photo}
                        className="size-8"
                        fallbackClassName="text-[11px]"
                    />
                    <div className="min-w-0">
                        <p className="max-w-56 truncate text-sm font-medium">
                            {row.employee.full_name}
                        </p>
                        <p className="max-w-56 truncate text-xs text-muted-foreground">
                            {row.employee.department?.name ?? '—'}
                        </p>
                    </div>
                </div>
            </TableCell>

            <TableCell className="text-right text-sm font-medium tabular-nums">
                {row.present_days}
            </TableCell>
            <TableCell className="text-right text-sm tabular-nums">
                {row.late_count > 0 ? (
                    <span className="text-amber-600 dark:text-amber-400">
                        {row.late_count}
                    </span>
                ) : (
                    <span className="text-muted-foreground">0</span>
                )}
            </TableCell>
            <TableCell className="hidden text-right text-sm tabular-nums md:table-cell">
                {row.half_day_count > 0 ? (
                    <span className="text-fuchsia-600 dark:text-fuchsia-400">
                        {row.half_day_count}
                    </span>
                ) : (
                    <span className="text-muted-foreground">0</span>
                )}
            </TableCell>
            <TableCell className="text-right text-sm tabular-nums">
                {row.absent_count > 0 ? (
                    <span className="text-rose-600 dark:text-rose-400">
                        {row.absent_count}
                    </span>
                ) : (
                    <span className="text-muted-foreground">0</span>
                )}
            </TableCell>
            <TableCell className="hidden text-right text-sm tabular-nums md:table-cell">
                {row.holiday_count > 0 ? (
                    <span className="text-indigo-600 dark:text-indigo-400">
                        {row.holiday_count}
                    </span>
                ) : (
                    <span className="text-muted-foreground">0</span>
                )}
            </TableCell>
            <TableCell className="hidden text-right text-sm tabular-nums md:table-cell">
                {row.overtime_hours > 0 ? (
                    <>
                        <span className="text-indigo-600 dark:text-indigo-400">
                            {row.overtime_hours}h
                        </span>
                        {awaiting > 0 && (
                            <span className="block text-[11px] text-amber-700 dark:text-amber-300">
                                {hours(awaiting)} awaiting approval
                            </span>
                        )}
                    </>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>
            {showNight && (
                <TableCell className="hidden text-right text-sm tabular-nums xl:table-cell">
                    {row.minutes.night > 0 ? (
                        hours(row.minutes.night)
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    )}
                </TableCell>
            )}

            <TableCell className="text-right">
                {rate === null ? (
                    <span className="text-sm text-muted-foreground">—</span>
                ) : (
                    <div className="flex items-center justify-end gap-2">
                        <div className="hidden h-1.5 w-16 overflow-hidden rounded-full bg-muted sm:block">
                            <div
                                className={cn(
                                    'h-full rounded-full',
                                    rateBar(rate),
                                )}
                                style={{ width: `${rate}%` }}
                            />
                        </div>
                        <span
                            className={cn(
                                'w-10 text-right text-sm font-semibold tabular-nums',
                                rateTone(rate),
                            )}
                        >
                            {rate}%
                        </span>
                    </div>
                )}
            </TableCell>

            <TableCell className="hidden text-right lg:table-cell">
                <div className="flex justify-end">
                    <Sparkline data={row.trend} />
                </div>
            </TableCell>
        </TableRow>
    );
}
