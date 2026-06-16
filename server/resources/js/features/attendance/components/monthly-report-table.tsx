import { LineChart } from 'lucide-react';
import { PersonAvatar } from '@/components/person-avatar';
import {
    Table,
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

/**
 * The monthly report — one summary row per employee: present days, late count,
 * absences, overtime and attendance rate, with an inline sparkline of the
 * worked-hours rhythm across the month.
 */
export function MonthlyReportTable({ report }: { report: MonthlyReport }) {
    if (report.rows.length === 0) {
        return <EmptyState />;
    }

    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
            <Table>
                <TableHeader>
                    <TableRow className="hover:bg-transparent">
                        <TableHead>Employee</TableHead>
                        <TableHead className="text-right">Present</TableHead>
                        <TableHead className="text-right">Late</TableHead>
                        <TableHead className="text-right">Absent</TableHead>
                        <TableHead className="hidden text-right md:table-cell">
                            Overtime
                        </TableHead>
                        <TableHead className="text-right">Rate</TableHead>
                        <TableHead className="hidden text-right lg:table-cell">
                            Trend
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {report.rows.map((row) => (
                        <Row key={row.employee.id} row={row} />
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function Row({ row }: { row: MonthlyRow }) {
    const rate = row.attendance_rate;

    return (
        <TableRow>
            <TableCell className="py-2.5">
                <div className="flex items-center gap-3">
                    <PersonAvatar
                        name={row.employee.full_name}
                        initials={row.employee.initials}
                        photo={row.employee.photo}
                        className="size-9"
                    />
                    <div className="min-w-0">
                        <p className="truncate text-sm font-medium">
                            {row.employee.full_name}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
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
                {row.overtime_hours > 0 ? (
                    <span className="text-indigo-600 dark:text-indigo-400">
                        {row.overtime_hours}h
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>

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

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <LineChart className="size-5" />
            </span>
            <p className="text-sm font-medium">No employees match this view</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Try a different department or search.
            </p>
        </div>
    );
}
