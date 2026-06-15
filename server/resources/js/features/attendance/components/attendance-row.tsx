import { PersonAvatar } from '@/components/person-avatar';
import { cn } from '@/lib/utils';
import { formatDuration, formatTime, STATUS_DOT } from '../constants';
import type { AttendanceRecord } from '../types';
import { AttendanceStatusBadge } from './attendance-status-badge';

/**
 * One employee's day as a compact list row (the dense alternative to the board).
 */
export function AttendanceRow({
    record,
    onOpen,
}: {
    record: AttendanceRecord;
    onOpen: (record: AttendanceRecord) => void;
}) {
    const employee = record.employee;

    return (
        <button
            type="button"
            onClick={() => onOpen(record)}
            className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/50"
        >
            <span className="relative">
                <PersonAvatar
                    name={employee?.full_name ?? 'Unknown'}
                    initials={employee?.initials ?? '?'}
                    photo={employee?.photo}
                    className="size-9"
                />
                <span
                    className={cn(
                        'absolute -right-0.5 -bottom-0.5 size-2.5 rounded-full ring-2 ring-card',
                        STATUS_DOT[record.status],
                    )}
                />
            </span>

            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">
                    {employee?.full_name ?? 'Unknown employee'}
                </p>
                <p className="truncate text-xs text-muted-foreground">
                    {employee?.department?.name ?? '—'}
                </p>
            </div>

            <div className="hidden w-40 items-center justify-between text-sm tabular-nums sm:flex">
                <span>{formatTime(record.first_in_at)}</span>
                <span className="text-muted-foreground/50">→</span>
                <span>{formatTime(record.last_out_at)}</span>
            </div>

            <div className="hidden w-20 text-right text-sm font-medium tabular-nums md:block">
                {record.worked_minutes > 0
                    ? formatDuration(record.worked_minutes)
                    : '—'}
            </div>

            <div className="hidden w-24 text-right text-xs md:block">
                {record.late_minutes > 0 ? (
                    <span className="text-amber-600 dark:text-amber-400">
                        {formatDuration(record.late_minutes)} late
                    </span>
                ) : record.overtime_minutes > 0 ? (
                    <span className="text-indigo-600 dark:text-indigo-400">
                        +{formatDuration(record.overtime_minutes)}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </div>

            <div className="w-24 text-right">
                <AttendanceStatusBadge status={record.status} />
            </div>
        </button>
    );
}
