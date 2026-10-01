import { CalendarRange } from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';
import { cn } from '@/lib/utils';
import {
    formatDuration,
    formatTime,
    STATUS_DOT,
    STATUS_LABELS,
    STATUS_TILE,
} from '../constants';
import type { WeekCell, WeeklyView } from '../types';

/** The legend statuses, in a stable order. */
const LEGEND: (keyof typeof STATUS_LABELS)[] = [
    'present',
    'late',
    'undertime',
    'half_day',
    'incomplete',
    'absent',
    'on_leave',
    'holiday',
    'day_off',
];

/**
 * The weekly grid — employees down, Mon–Sun across, each cell a status tile. The
 * one place a matrix genuinely earns its keep: comparing a week across people.
 * Clicking a cell jumps to that day's log.
 */
export function WeeklyGrid({
    week,
    onPickDay,
    resetKey,
}: {
    week: WeeklyView;
    /** The server filters on screen — changing them returns to page one. */
    resetKey: string;
    onPickDay: (date: string) => void;
}) {
    const page = useClientPagination(week.rows, resetKey, 25);

    return (
        <div className="flex flex-col gap-3">
            <TableCard
                title="Week at a glance"
                count={week.rows.length}
                description="Pick a day to open its log."
            >
                <DataTable className="min-w-[44rem] table-fixed">
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-56">Employee</TableHead>
                            {week.days.map((day) => (
                                <TableHead
                                    key={day.date}
                                    className={cn(
                                        'px-1 text-center',
                                        day.is_today && 'bg-[#0ABFBF]/10',
                                    )}
                                >
                                    <button
                                        type="button"
                                        onClick={() => onPickDay(day.date)}
                                        className={cn(
                                            'inline-flex items-baseline gap-1 text-[11px] font-semibold tracking-wide uppercase hover:text-foreground',
                                            day.is_today
                                                ? 'text-[#0a8b91] dark:text-[#0ABFBF]'
                                                : 'text-muted-foreground',
                                        )}
                                        aria-label={`Open the log for ${day.weekday} ${day.day}`}
                                    >
                                        {day.weekday}
                                        <span className="text-sm tabular-nums">
                                            {day.day}
                                        </span>
                                    </button>
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {week.rows.length === 0 && (
                            <EmptyTableRow
                                colSpan={8}
                                icon={CalendarRange}
                                title="No employees match this view"
                                description="Try a different department or search."
                            />
                        )}

                        {page.rows.map((row) => (
                            <TableRow key={row.employee.id}>
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
                                            <p className="truncate text-sm font-medium">
                                                {row.employee.full_name}
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {row.employee.department
                                                    ?.name ?? '—'}
                                            </p>
                                        </div>
                                    </div>
                                </TableCell>

                                {row.cells.map((cell, index) => (
                                    <TableCell
                                        key={cell.date}
                                        className={cn(
                                            'px-1 py-1',
                                            week.days[index]?.is_today &&
                                                'bg-[#0ABFBF]/5',
                                        )}
                                    >
                                        <WeekTile
                                            cell={cell}
                                            onPick={onPickDay}
                                        />
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))}
                    </TableBody>
                </DataTable>

                {/* Legend */}
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-border px-4 py-2">
                    {LEGEND.map((status) => (
                        <span
                            key={status}
                            className="inline-flex items-center gap-1.5 text-[11px] text-muted-foreground"
                        >
                            <span
                                className={cn(
                                    'size-2.5 rounded-[3px]',
                                    STATUS_DOT[status],
                                )}
                            />
                            {STATUS_LABELS[status]}
                        </span>
                    ))}
                </div>
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

function WeekTile({
    cell,
    onPick,
}: {
    cell: WeekCell;
    onPick: (date: string) => void;
}) {
    const timeZone = useOrganizationTimeZone();

    if (!cell.status) {
        return (
            <div className="h-10 rounded-md border border-dashed border-border/50" />
        );
    }

    const worked = cell.worked_minutes > 0;
    const label = worked
        ? formatTime(cell.first_in_at, timeZone)
        : cell.status === 'on_leave'
          ? 'Leave'
          : cell.status === 'holiday'
            ? 'Holiday'
            : cell.status === 'absent'
              ? 'Absent'
              : '';

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    onClick={() => onPick(cell.date)}
                    className={cn(
                        'flex h-10 w-full flex-col items-center justify-center gap-0.5 rounded-md px-1 text-[11px] font-medium ring-1 ring-transparent transition-all hover:ring-2',
                        STATUS_TILE[cell.status],
                    )}
                >
                    <span className="truncate tabular-nums">{label}</span>
                    {cell.late_minutes > 0 && (
                        <span className="text-[9px] font-semibold opacity-80">
                            +{formatDuration(cell.late_minutes)}
                        </span>
                    )}
                </button>
            </TooltipTrigger>
            <TooltipContent className="flex flex-col gap-0.5">
                <span className="font-medium">
                    {STATUS_LABELS[cell.status]}
                </span>
                {cell.holiday && (
                    <span className="text-primary-foreground/80">
                        {cell.holiday}
                    </span>
                )}
                {worked && (
                    <span className="text-primary-foreground/80 tabular-nums">
                        {formatTime(cell.first_in_at, timeZone)} →{' '}
                        {formatTime(cell.last_out_at, timeZone)} ·{' '}
                        {formatDuration(cell.worked_minutes)}
                    </span>
                )}
                {cell.late_minutes > 0 && (
                    <span className="text-primary-foreground/80">
                        Late by {formatDuration(cell.late_minutes)}
                    </span>
                )}
            </TooltipContent>
        </Tooltip>
    );
}
