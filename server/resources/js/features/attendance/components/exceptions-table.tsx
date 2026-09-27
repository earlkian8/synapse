import {
    Clock,
    LogOut,
    MapPinX,
    ScanLine,
    ShieldCheck,
    SplitSquareHorizontal,
    TimerOff,
    UserX,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo } from 'react';
import { DataTable, rowOpens, TableCard } from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';
import { cn } from '@/lib/utils';
import {
    formatDuration,
    formatTime,
    LATE_EXCEPTION_MINUTES,
} from '../constants';
import type { AttendanceFlag, AttendanceRecord } from '../types';

type Group = {
    key: string;
    title: string;
    icon: LucideIcon;
    tone: string;
    records: AttendanceRecord[];
    detail: (record: AttendanceRecord) => string;
};

/**
 * The exceptions table — the day's problems pulled out of the log into a short,
 * actionable table ordered by kind (missing time-out, badly late, unscheduled
 * absence…). Each row opens the person's day to resolve it. This is what makes
 * the board feel like it's doing HR work, not just displaying rows.
 */
export function ExceptionsTable({
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
    const timeZone = useOrganizationTimeZone();

    const groups = useMemo<Group[]>(() => {
        const missingOut = records.filter((r) => r.status === 'incomplete');
        const veryLate = records
            .filter((r) => r.late_minutes >= LATE_EXCEPTION_MINUTES)
            .sort((a, b) => b.late_minutes - a.late_minutes);
        const absences = records.filter((r) => r.status === 'absent');
        const halfDays = records.filter((r) => r.status === 'half_day');
        // What capture and the end-of-day job found (ADR 0040, ADR 0041).
        const flagged = (flag: AttendanceFlag) =>
            records.filter((r) => r.flags?.includes(flag));

        return [
            {
                key: 'missing-out',
                title: 'Missing time-out',
                icon: LogOut,
                tone: 'text-rose-600 bg-rose-500/10 dark:text-rose-400',
                records: missingOut,
                detail: (r: AttendanceRecord) =>
                    r.first_in_at
                        ? `In at ${formatTime(r.first_in_at, timeZone)} · never clocked out`
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
                detail: (r: AttendanceRecord) =>
                    r.first_in_at
                        ? 'Punched, but past the company’s limit'
                        : 'No punches · not on leave',
            },
            {
                key: 'half-day',
                title: 'Half days',
                icon: SplitSquareHorizontal,
                tone: 'text-fuchsia-600 bg-fuchsia-500/10 dark:text-fuchsia-400',
                records: halfDays,
                detail: (r: AttendanceRecord) =>
                    r.late_minutes > 0
                        ? `${formatDuration(r.late_minutes)} late`
                        : `${formatDuration(r.worked_minutes)} worked`,
            },
            {
                key: 'outside',
                title: 'Punched away from the site',
                icon: MapPinX,
                tone: 'text-rose-600 bg-rose-500/10 dark:text-rose-400',
                records: flagged('outside_geofence'),
                detail: () => 'Not shown to be on site',
            },
            {
                key: 'auto-closed',
                title: 'Closed automatically',
                icon: TimerOff,
                tone: 'text-amber-600 bg-amber-500/10 dark:text-amber-400',
                records: flagged('auto_closed'),
                detail: (r: AttendanceRecord) =>
                    r.last_out_at
                        ? `Clock-out written for ${formatTime(r.last_out_at, timeZone)}`
                        : 'Clock-out written by the policy',
            },
            {
                key: 'device',
                title: 'Device punches out of order',
                icon: ScanLine,
                tone: 'text-amber-600 bg-amber-500/10 dark:text-amber-400',
                records: flagged('device_sequence_anomaly'),
                detail: () => 'Recorded as the device sent them',
            },
            {
                key: 'skew',
                title: 'Clock was off',
                icon: Clock,
                tone: 'text-amber-600 bg-amber-500/10 dark:text-amber-400',
                records: flagged('clock_skew'),
                detail: () => 'Stamped by a phone or device clock that was off',
            },
        ].filter((group) => group.records.length > 0);
    }, [records, timeZone]);

    const rows = groups.flatMap((group) =>
        group.records.map((record) => ({ group, record })),
    );

    return (
        <TableCard
            title="Exceptions"
            count={rows.length > 0 ? rows.length : undefined}
            actions={
                <span
                    className={cn(
                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                        rows.length > 0
                            ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400'
                            : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                    )}
                >
                    {rows.length > 0 ? (
                        'To review'
                    ) : (
                        <>
                            <ShieldCheck className="size-3.5" />
                            All clear — everyone is accounted for
                        </>
                    )}
                </span>
            }
        >
            {rows.length > 0 && (
                <div className="max-h-72 overflow-y-auto">
                    <DataTable>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Employee</TableHead>
                                <TableHead>Exception</TableHead>
                                <TableHead className="hidden md:table-cell">
                                    Detail
                                </TableHead>
                                <TableHead className="w-24" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map(({ group, record }) => {
                                const act = () =>
                                    canManage
                                        ? onResolve(record)
                                        : onOpen(record);
                                const name =
                                    record.employee?.full_name ??
                                    'Unknown employee';

                                return (
                                    <TableRow
                                        key={`${group.key}-${record.employee?.id ?? record.id}`}
                                        {...rowOpens(act)}
                                    >
                                        <TableCell>
                                            <div className="flex min-w-0 items-center gap-2.5">
                                                <PersonAvatar
                                                    name={name}
                                                    initials={
                                                        record.employee
                                                            ?.initials ?? '?'
                                                    }
                                                    photo={
                                                        record.employee?.photo
                                                    }
                                                    className="size-7"
                                                    fallbackClassName="text-[10px]"
                                                />
                                                <span className="max-w-52 truncate text-sm font-medium">
                                                    {name}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <span className="inline-flex items-center gap-2 text-sm">
                                                <span
                                                    className={cn(
                                                        'flex size-6 shrink-0 items-center justify-center rounded-md',
                                                        group.tone,
                                                    )}
                                                >
                                                    <group.icon className="size-3.5" />
                                                </span>
                                                {group.title}
                                            </span>
                                        </TableCell>
                                        <TableCell className="hidden text-sm text-muted-foreground md:table-cell">
                                            {group.detail(record)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="h-7 px-2 text-xs"
                                                onClick={act}
                                            >
                                                {canManage ? 'Resolve' : 'View'}
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </DataTable>
                </div>
            )}
        </TableCard>
    );
}
