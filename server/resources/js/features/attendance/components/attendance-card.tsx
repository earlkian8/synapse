import { ArrowRight, Coffee, LogIn, LogOut } from 'lucide-react';
import { PersonAvatar } from '@/components/person-avatar';
import { cn } from '@/lib/utils';
import { formatDuration, formatTime, STATUS_DOT } from '../constants';
import type { AttendanceRecord } from '../types';
import { AttendanceStatusBadge } from './attendance-status-badge';

/**
 * One employee's day, as a card in the roster grid. Click to open the detail
 * drawer with the full punch timeline.
 */
export function AttendanceCard({
    record,
    onOpen,
}: {
    record: AttendanceRecord;
    onOpen: (record: AttendanceRecord) => void;
}) {
    const employee = record.employee;
    const worked = record.worked_minutes > 0;

    return (
        <button
            type="button"
            onClick={() => onOpen(record)}
            className="group flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 text-left shadow-sm transition-all hover:border-[#0ABFBF]/40 hover:shadow-md dark:border-sidebar-border"
        >
            <div className="flex items-start gap-3">
                <span className="relative">
                    <PersonAvatar
                        name={employee?.full_name ?? 'Unknown'}
                        initials={employee?.initials ?? '?'}
                        photo={employee?.photo}
                        className="size-10"
                    />
                    <span
                        className={cn(
                            'absolute -right-0.5 -bottom-0.5 size-3 rounded-full ring-2 ring-card',
                            STATUS_DOT[record.status],
                        )}
                    />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">
                        {employee?.full_name ?? 'Unknown employee'}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {employee?.position?.title ?? 'No position'}
                    </p>
                </div>
                <AttendanceStatusBadge status={record.status} />
            </div>

            {/* In → Out */}
            <div className="flex items-center justify-between rounded-lg bg-muted/50 px-3 py-2">
                <Time
                    icon={LogIn}
                    label="In"
                    value={formatTime(record.first_in_at)}
                />
                <ArrowRight className="size-3.5 text-muted-foreground/60" />
                <Time
                    icon={LogOut}
                    label="Out"
                    value={formatTime(record.last_out_at)}
                    align="right"
                />
            </div>

            {/* Totals */}
            <div className="flex flex-wrap items-center gap-1.5 text-[11px]">
                <Chip className="bg-muted text-foreground/80">
                    {worked ? formatDuration(record.worked_minutes) : '—'}{' '}
                    worked
                </Chip>
                {record.break_minutes > 0 && (
                    <Chip className="bg-muted text-muted-foreground">
                        <Coffee className="size-3" />
                        {formatDuration(record.break_minutes)}
                    </Chip>
                )}
                {record.late_minutes > 0 && (
                    <Chip className="bg-amber-500/10 text-amber-600 dark:text-amber-400">
                        {formatDuration(record.late_minutes)} late
                    </Chip>
                )}
                {record.overtime_minutes > 0 && (
                    <Chip className="bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                        +{formatDuration(record.overtime_minutes)} OT
                    </Chip>
                )}
                {record.is_manual && (
                    <Chip className="bg-slate-500/10 text-slate-500">
                        Manual
                    </Chip>
                )}
            </div>
        </button>
    );
}

function Time({
    icon: Icon,
    label,
    value,
    align = 'left',
}: {
    icon: typeof LogIn;
    label: string;
    value: string;
    align?: 'left' | 'right';
}) {
    return (
        <div className={cn('flex flex-col', align === 'right' && 'items-end')}>
            <span className="inline-flex items-center gap-1 text-[10px] text-muted-foreground uppercase">
                <Icon className="size-3" />
                {label}
            </span>
            <span className="text-sm font-medium tabular-nums">{value}</span>
        </div>
    );
}

function Chip({
    children,
    className,
}: {
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-medium',
                className,
            )}
        >
            {children}
        </span>
    );
}
