import { CalendarRange } from 'lucide-react';
import { PersonAvatar } from '@/components/person-avatar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import {
    formatDuration,
    formatTime,
    STATUS_DOT,
    STATUS_LABELS,
    STATUS_TILE,
} from '../constants';
import type { WeekCell, WeeklyView } from '../types';

const GRID_COLUMNS = 'minmax(180px, 1.4fr) repeat(7, minmax(0, 1fr))';

/** The legend statuses, in a stable order. */
const LEGEND: (keyof typeof STATUS_LABELS)[] = [
    'present',
    'late',
    'undertime',
    'incomplete',
    'absent',
    'on_leave',
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
}: {
    week: WeeklyView;
    onPickDay: (date: string) => void;
}) {
    if (week.rows.length === 0) {
        return <EmptyState />;
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
                <div className="min-w-[640px]">
                    {/* Header */}
                    <div
                        className="grid border-b border-border bg-muted/30"
                        style={{ gridTemplateColumns: GRID_COLUMNS }}
                    >
                        <div className="sticky left-0 z-10 bg-muted/30 px-4 py-2.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase backdrop-blur">
                            Employee
                        </div>
                        {week.days.map((day) => (
                            <div
                                key={day.date}
                                className={cn(
                                    'flex flex-col items-center justify-center py-2 text-center',
                                    day.is_today && 'bg-[#0ABFBF]/10',
                                )}
                            >
                                <span
                                    className={cn(
                                        'text-[11px] font-semibold tracking-wide uppercase',
                                        day.is_today
                                            ? 'text-[#0a8b91] dark:text-[#0ABFBF]'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {day.weekday}
                                </span>
                                <span
                                    className={cn(
                                        'text-sm tabular-nums',
                                        day.is_today &&
                                            'font-bold text-[#0a8b91] dark:text-[#0ABFBF]',
                                    )}
                                >
                                    {day.day}
                                </span>
                            </div>
                        ))}
                    </div>

                    {/* Rows */}
                    {week.rows.map((row) => (
                        <div
                            key={row.employee.id}
                            className="grid border-b border-border/60 last:border-0 hover:bg-muted/20"
                            style={{ gridTemplateColumns: GRID_COLUMNS }}
                        >
                            <div className="sticky left-0 z-10 flex items-center gap-2.5 bg-card px-4 py-2">
                                <PersonAvatar
                                    name={row.employee.full_name}
                                    initials={row.employee.initials}
                                    photo={row.employee.photo}
                                    className="size-8"
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

                            {row.cells.map((cell) => (
                                <WeekTile
                                    key={cell.date}
                                    cell={cell}
                                    onPick={onPickDay}
                                />
                            ))}
                        </div>
                    ))}
                </div>
            </div>

            {/* Legend */}
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-1">
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
    if (!cell.status) {
        return (
            <div className="p-1">
                <div className="h-11 rounded-md border border-dashed border-border/50" />
            </div>
        );
    }

    const worked = cell.worked_minutes > 0;
    const label = worked
        ? formatTime(cell.first_in_at)
        : cell.status === 'on_leave'
          ? 'Leave'
          : cell.status === 'absent'
            ? 'Absent'
            : '';

    return (
        <div className="p-1">
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        type="button"
                        onClick={() => onPick(cell.date)}
                        className={cn(
                            'flex h-11 w-full flex-col items-center justify-center gap-0.5 rounded-md px-1 text-[11px] font-medium ring-1 ring-transparent transition-all hover:ring-2',
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
                    {worked && (
                        <span className="text-primary-foreground/80 tabular-nums">
                            {formatTime(cell.first_in_at)} →{' '}
                            {formatTime(cell.last_out_at)} ·{' '}
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
        </div>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <CalendarRange className="size-5" />
            </span>
            <p className="text-sm font-medium">No employees match this view</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Try a different department or search.
            </p>
        </div>
    );
}
