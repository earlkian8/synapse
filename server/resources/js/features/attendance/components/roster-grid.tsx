import { CalendarClock, Pin, Users } from 'lucide-react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { SHIFT_SOURCE_LABELS } from '../constants';
import type { RosterCell, RosterRow, RosterView } from '../types';

const GRID_COLUMNS = 'minmax(180px, 1.4fr) repeat(7, minmax(0, 1fr))';

/**
 * The roster — employees down, the week across, each cell the shift that person
 * is due to work. Unlike the weekly grid beside it, this shows the **plan**: it
 * reads forward as happily as back, and a cell says where its shift came from,
 * so "why is Ben on nights?" is answerable without leaving the screen.
 *
 * Clicking a cell opens the override for that person and date.
 */
export function RosterGrid({
    roster,
    canManage,
    onPickCell,
}: {
    roster: RosterView;
    canManage: boolean;
    onPickCell: (row: RosterRow, cell: RosterCell) => void;
}) {
    if (roster.rows.length === 0) {
        return <EmptyState />;
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
                <div className="min-w-[720px]">
                    <div
                        className="grid border-b border-border bg-muted/30"
                        style={{ gridTemplateColumns: GRID_COLUMNS }}
                    >
                        <div className="sticky left-0 z-10 bg-muted/30 px-4 py-2.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase backdrop-blur">
                            Employee
                        </div>
                        {roster.days.map((day) => (
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
                                {day.holiday && (
                                    <span className="mt-0.5 max-w-full truncate px-1 text-[10px] text-indigo-600 dark:text-indigo-400">
                                        {day.holiday}
                                    </span>
                                )}
                            </div>
                        ))}
                    </div>

                    {roster.rows.map((row) => (
                        <div
                            key={row.employee.id}
                            className="grid border-b border-border/60 last:border-b-0"
                            style={{ gridTemplateColumns: GRID_COLUMNS }}
                        >
                            <div className="sticky left-0 z-10 flex items-center gap-2.5 bg-card px-4 py-2">
                                <PersonAvatar
                                    name={row.employee.full_name}
                                    initials={row.employee.initials}
                                    photo={row.employee.photo}
                                    className="size-7"
                                />
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium">
                                        {row.employee.full_name}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {row.employee.department?.name ??
                                            row.employee.employee_no}
                                    </p>
                                </div>
                            </div>

                            {row.cells.map((cell) => (
                                <ShiftCell
                                    key={cell.date}
                                    cell={cell}
                                    employeeName={row.employee.full_name}
                                    canManage={canManage}
                                    onPick={() => onPickCell(row, cell)}
                                />
                            ))}
                        </div>
                    ))}
                </div>
            </div>

            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Pin className="size-3" />A pinned cell is a one-off override.
                {canManage
                    ? ' Click any cell to change that day.'
                    : ' Ask HR to change a day.'}
            </p>
        </div>
    );
}

function ShiftCell({
    cell,
    employeeName,
    canManage,
    onPick,
}: {
    cell: RosterCell;
    employeeName: string;
    canManage: boolean;
    onPick: () => void;
}) {
    const isOverride = cell.source === 'roster';

    const content = (
        <span
            className={cn(
                'relative flex h-full min-h-[3.25rem] w-full flex-col items-center justify-center gap-0.5 px-1.5 py-2 text-center transition-colors',
                cell.is_working_day
                    ? 'text-foreground'
                    : 'bg-muted/30 text-muted-foreground',
                isOverride && 'bg-amber-500/10',
                canManage && 'hover:bg-muted focus-visible:bg-muted',
            )}
        >
            {isOverride && (
                <Pin className="absolute top-1 right-1 size-3 text-amber-600 dark:text-amber-400" />
            )}
            <span
                className={cn(
                    'text-[11px] leading-tight font-medium tabular-nums',
                    !cell.is_working_day && 'font-normal',
                )}
            >
                {cell.label}
            </span>
            {cell.is_working_day && cell.schedule_name && (
                <span className="max-w-full truncate text-[10px] text-muted-foreground">
                    {cell.schedule_name}
                </span>
            )}
        </span>
    );

    const trigger = canManage ? (
        <button
            type="button"
            onClick={onPick}
            className="h-full border-l border-border/60 text-left"
            aria-label={`${employeeName} on ${cell.date}: ${cell.label}`}
        >
            {content}
        </button>
    ) : (
        <div className="h-full border-l border-border/60">{content}</div>
    );

    return (
        <Tooltip>
            <TooltipTrigger asChild>{trigger}</TooltipTrigger>
            <TooltipContent>
                <p className="font-medium">{cell.label}</p>
                <p className="text-xs opacity-80">
                    {SHIFT_SOURCE_LABELS[cell.source]}
                    {cell.schedule_name ? ` · ${cell.schedule_name}` : ''}
                </p>
                {cell.reason && (
                    <p className="text-xs opacity-80">{cell.reason}</p>
                )}
            </TooltipContent>
        </Tooltip>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <Users className="size-5" />
            </span>
            <p className="text-sm font-medium">Nobody to roster</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Try a different department or search, or add employees first.
            </p>
        </div>
    );
}

/** The toolbar button that opens the bulk assign dialog. */
export function AssignScheduleButton({ onClick }: { onClick: () => void }) {
    return (
        <Button variant="outline" size="sm" onClick={onClick}>
            <CalendarClock className="size-4" />
            Assign a schedule
        </Button>
    );
}
