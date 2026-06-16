import { ChevronRight, Clock, LogOut, ShieldCheck, UserX } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import { cn } from '@/lib/utils';
import {
    formatDuration,
    formatTime,
    LATE_EXCEPTION_MINUTES,
} from '../constants';
import type { AttendanceRecord } from '../types';

type Group = {
    key: string;
    title: string;
    icon: LucideIcon;
    tone: string;
    records: AttendanceRecord[];
    detail: (record: AttendanceRecord) => string;
};

/**
 * The exceptions panel — the day's problems pulled out of the table into a short,
 * actionable list grouped by kind (missing time-out, badly late, unscheduled
 * absence). Each item opens the person's day to resolve it. This is what makes
 * the board feel like it's doing HR work, not just displaying rows.
 */
export function ExceptionsPanel({
    records,
    canManage,
    onResolve,
    onOpen,
}: {
    records: AttendanceRecord[];
    canManage: boolean;
    onResolve: (record: AttendanceRecord) => void;
    onOpen: (record: AttendanceRecord) => void;
}) {
    const groups = useMemo<Group[]>(() => {
        const missingOut = records.filter((r) => r.status === 'incomplete');
        const veryLate = records
            .filter((r) => r.late_minutes >= LATE_EXCEPTION_MINUTES)
            .sort((a, b) => b.late_minutes - a.late_minutes);
        const absences = records.filter((r) => r.status === 'absent');

        return [
            {
                key: 'missing-out',
                title: 'Missing time-out',
                icon: LogOut,
                tone: 'text-rose-600 bg-rose-500/10 dark:text-rose-400',
                records: missingOut,
                detail: (r: AttendanceRecord) =>
                    r.first_in_at
                        ? `In at ${formatTime(r.first_in_at)} · never clocked out`
                        : 'Never clocked out',
            },
            {
                key: 'late',
                title: `Late over ${LATE_EXCEPTION_MINUTES}m`,
                icon: Clock,
                tone: 'text-amber-600 bg-amber-500/10 dark:text-amber-400',
                records: veryLate,
                detail: (r: AttendanceRecord) =>
                    `${formatDuration(r.late_minutes)} late`,
            },
            {
                key: 'absent',
                title: 'Unscheduled absences',
                icon: UserX,
                tone: 'text-rose-600 bg-rose-500/10 dark:text-rose-400',
                records: absences,
                detail: () => 'No punches · not on leave',
            },
        ].filter((group) => group.records.length > 0);
    }, [records]);

    const total = groups.reduce((sum, group) => sum + group.records.length, 0);

    return (
        <aside className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
            <div className="flex items-center justify-between">
                <h2 className="text-sm font-semibold">Exceptions</h2>
                <span
                    className={cn(
                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                        total > 0
                            ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400'
                            : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                    )}
                >
                    {total > 0 ? `${total} to review` : 'All clear'}
                </span>
            </div>

            {total === 0 ? (
                <div className="flex flex-col items-center gap-2 py-8 text-center">
                    <span className="flex size-10 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                        <ShieldCheck className="size-5" />
                    </span>
                    <p className="text-sm font-medium">No exceptions today</p>
                    <p className="max-w-[14rem] text-xs text-muted-foreground">
                        Everyone's clocked in on time and accounted for.
                    </p>
                </div>
            ) : (
                <div className="flex flex-col gap-4">
                    {groups.map((group) => (
                        <section
                            key={group.key}
                            className="flex flex-col gap-1.5"
                        >
                            <div className="flex items-center gap-2">
                                <span
                                    className={cn(
                                        'flex size-6 items-center justify-center rounded-md',
                                        group.tone,
                                    )}
                                >
                                    <group.icon className="size-3.5" />
                                </span>
                                <span className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    {group.title}
                                </span>
                                <span className="text-xs font-medium text-muted-foreground tabular-nums">
                                    {group.records.length}
                                </span>
                            </div>

                            <ul className="flex flex-col gap-1">
                                {group.records.map((record) => (
                                    <li key={record.employee?.id ?? record.id}>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                canManage
                                                    ? onResolve(record)
                                                    : onOpen(record)
                                            }
                                            className="group flex w-full items-center gap-2.5 rounded-lg px-2 py-1.5 text-left transition-colors hover:bg-muted/60"
                                        >
                                            <PersonAvatar
                                                name={
                                                    record.employee
                                                        ?.full_name ?? 'Unknown'
                                                }
                                                initials={
                                                    record.employee?.initials ??
                                                    '?'
                                                }
                                                photo={record.employee?.photo}
                                                className="size-7"
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">
                                                    {record.employee
                                                        ?.full_name ??
                                                        'Unknown employee'}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {group.detail(record)}
                                                </p>
                                            </div>
                                            <span className="text-xs font-medium text-[#0ABFBF] opacity-0 transition-opacity group-hover:opacity-100">
                                                {canManage ? 'Resolve' : 'View'}
                                            </span>
                                            <ChevronRight className="size-4 shrink-0 text-muted-foreground/50" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>
            )}
        </aside>
    );
}
