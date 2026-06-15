import { BadgeCheck, CalendarDays, Pencil, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatDuration } from '../constants';
import { attendanceRoutes } from '../routes';
import type { AttendanceRecord } from '../types';
import { AttendanceStatusBadge } from './attendance-status-badge';
import { PunchTimeline } from './punch-timeline';

type Props = {
    record: AttendanceRecord | null;
    canManage: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onEdit: (record: AttendanceRecord) => void;
    onApprove: (record: AttendanceRecord) => void;
    onDelete: (record: AttendanceRecord) => void;
};

export function RecordDetailSheet({
    record,
    canManage,
    open,
    onOpenChange,
    onEdit,
    onApprove,
    onDelete,
}: Props) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-lg"
            >
                {record && (
                    <Body
                        key={`${record.hashid ?? record.employee?.id}-${record.work_date}`}
                        record={record}
                        canManage={canManage}
                        onEdit={onEdit}
                        onApprove={onApprove}
                        onDelete={onDelete}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function Body({
    record,
    canManage,
    onEdit,
    onApprove,
    onDelete,
}: {
    record: AttendanceRecord;
    canManage: boolean;
    onEdit: (record: AttendanceRecord) => void;
    onApprove: (record: AttendanceRecord) => void;
    onDelete: (record: AttendanceRecord) => void;
}) {
    const employee = record.employee;
    const [detail, setDetail] = useState<AttendanceRecord>(record);

    // Enrich with the full punch timeline the board list doesn't carry. A
    // transient roster row (no hashid) has nothing to fetch — the keyed remount
    // already seeds `detail` from the record.
    useEffect(() => {
        if (!record.hashid) {
            return;
        }

        let active = true;

        fetch(attendanceRoutes.show(record.hashid), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((payload) => {
                if (active && payload?.data) {
                    setDetail(payload.data as AttendanceRecord);
                }
            })
            .catch(() => undefined);

        return () => {
            active = false;
        };
    }, [record]);

    const punches = detail.punches ?? [];
    const hasRecord = Boolean(record.hashid);
    const needsApproval = detail.approval_status === 'pending';

    return (
        <div className="flex h-full flex-col">
            <SheetHeader className="border-b border-border px-6 py-4">
                <div className="flex items-center gap-3">
                    <PersonAvatar
                        name={employee?.full_name ?? 'Unknown employee'}
                        initials={employee?.initials ?? '?'}
                        photo={employee?.photo}
                        className="size-11"
                        fallbackClassName="text-sm"
                    />
                    <div className="min-w-0 flex-1">
                        <SheetTitle className="truncate text-base">
                            {employee?.full_name ?? 'Unknown employee'}
                        </SheetTitle>
                        <p className="truncate text-xs text-muted-foreground">
                            {employee?.position?.title ?? 'No position'}
                            {employee?.department
                                ? ` · ${employee.department.name}`
                                : ''}
                        </p>
                    </div>
                    <AttendanceStatusBadge status={record.status} />
                </div>
            </SheetHeader>

            <div className="flex-1 space-y-6 px-6 py-6">
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <CalendarDays className="size-4" />
                    {formatDate(record.work_date)}
                    {record.scheduled_start && record.scheduled_end && (
                        <span className="text-xs">
                            · Scheduled {record.scheduled_start}–
                            {record.scheduled_end}
                        </span>
                    )}
                </div>

                {/* Totals */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Stat
                        label="Worked"
                        value={formatDuration(detail.worked_minutes)}
                    />
                    <Stat
                        label="Break"
                        value={formatDuration(detail.break_minutes)}
                    />
                    <Stat
                        label="Late"
                        value={formatDuration(detail.late_minutes)}
                        tone={detail.late_minutes > 0 ? 'amber' : undefined}
                    />
                    <Stat
                        label="Overtime"
                        value={formatDuration(detail.overtime_minutes)}
                        tone={
                            detail.overtime_minutes > 0 ? 'indigo' : undefined
                        }
                    />
                </div>

                {/* Punch timeline */}
                <div>
                    <p className="mb-3 text-xs font-medium text-muted-foreground">
                        Punch timeline
                    </p>
                    <PunchTimeline punches={punches} />
                </div>

                {detail.remarks && (
                    <div>
                        <p className="mb-1 text-xs font-medium text-muted-foreground">
                            Remarks
                        </p>
                        <p className="rounded-lg bg-muted/50 px-3 py-2 text-sm whitespace-pre-wrap">
                            {detail.remarks}
                        </p>
                    </div>
                )}

                {detail.approval_status && (
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        <BadgeCheck className="size-3.5" />
                        {detail.approval_status === 'approved'
                            ? `Approved${detail.approver ? ` by ${detail.approver}` : ''}`
                            : `Approval ${detail.approval_status}`}
                    </div>
                )}
            </div>

            {canManage && (
                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border px-6 py-4">
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => onEdit(record)}
                        >
                            <Pencil className="size-4" />
                            {hasRecord ? 'Correct' : 'Record'}
                        </Button>
                        {hasRecord && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8 text-muted-foreground hover:text-destructive"
                                aria-label="Delete record"
                                onClick={() => onDelete(record)}
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        )}
                    </div>

                    {needsApproval && (
                        <Button
                            size="sm"
                            className="bg-emerald-600 text-white hover:bg-emerald-600/90"
                            onClick={() => onApprove(record)}
                        >
                            <BadgeCheck className="size-4" />
                            Approve
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}

function Stat({
    label,
    value,
    tone,
}: {
    label: string;
    value: string;
    tone?: 'amber' | 'indigo';
}) {
    const toneClass =
        tone === 'amber'
            ? 'text-amber-600 dark:text-amber-400'
            : tone === 'indigo'
              ? 'text-indigo-600 dark:text-indigo-400'
              : '';

    return (
        <div className="rounded-lg border border-sidebar-border/70 bg-card p-3 dark:border-sidebar-border">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p
                className={`mt-0.5 text-sm font-semibold tabular-nums ${toneClass}`}
            >
                {value}
            </p>
        </div>
    );
}

function formatDate(date: string | null): string {
    if (!date) {
        return '—';
    }

    return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    });
}
