import {
    BadgeCheck,
    CircleDashed,
    Pencil,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
} from '@/components/modal';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    CHIP_FLAGS,
    FLAG_LABELS,
    FLAG_TONES,
    formatDuration,
} from '../constants';
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
    onReapply: (record: AttendanceRecord) => void;
    onDelete: (record: AttendanceRecord) => void;
};

/**
 * One employee's day, opened in the middle of the screen.
 *
 * The day reads top to bottom in the order somebody checks it: **who and when**
 * in the header, **what it added up to** in a totals strip that stays put, then
 * **the trail** — every punch with the photo taken at it — and finally anything
 * written about the day.
 *
 * The trail carries its own evidence rather than a column beside it. A separate
 * verification rail meant a day with no photos left half the modal empty, and a
 * day with photos said each punch twice; putting the photo on its own row says
 * it once, and lets a punch that has none say so.
 */
export function RecordDetailDialog({
    record,
    canManage,
    open,
    onOpenChange,
    onEdit,
    onApprove,
    onReapply,
    onDelete,
}: Props) {
    if (!record) {
        return null;
    }

    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                <Body
                    key={`${record.hashid ?? record.employee?.id}-${record.work_date}`}
                    record={record}
                    canManage={canManage}
                    onEdit={onEdit}
                    onApprove={onApprove}
                    onReapply={onReapply}
                    onDelete={onDelete}
                />
            </ModalContent>
        </Modal>
    );
}

function Body({
    record,
    canManage,
    onEdit,
    onApprove,
    onReapply,
    onDelete,
}: {
    record: AttendanceRecord;
    canManage: boolean;
    onEdit: (record: AttendanceRecord) => void;
    onApprove: (record: AttendanceRecord) => void;
    onReapply: (record: AttendanceRecord) => void;
    onDelete: (record: AttendanceRecord) => void;
}) {
    const employee = record.employee;
    const [detail, setDetail] = useState<AttendanceRecord>(record);

    // Enrich with the full punch trail the board list doesn't carry. A
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
    const withPhoto = punches.filter((punch) => punch.photo).length;
    const hasRecord = Boolean(record.hashid);
    const needsApproval = detail.approval_status === 'pending';
    const approval = detail.approval_status;
    const chips = (detail.flags ?? []).filter((flag) =>
        CHIP_FLAGS.includes(flag),
    );
    const unapproved = Math.max(
        0,
        detail.overtime_minutes -
            (detail.approved_overtime_minutes ?? detail.overtime_minutes),
    );

    // Night, rest-day and holiday minutes are tags over the worked minutes, so
    // they only earn a place in the band when the day has some.
    const tags = [
        { label: 'Night', minutes: detail.night_minutes ?? 0 },
        { label: 'Rest day', minutes: detail.rest_day_minutes ?? 0 },
        { label: 'Holiday', minutes: detail.holiday_minutes ?? 0 },
    ].filter((tag) => tag.minutes > 0);

    return (
        <>
            <ModalHeader
                className="py-3.5"
                icon={
                    <PersonAvatar
                        name={employee?.full_name ?? 'Unknown employee'}
                        initials={employee?.initials ?? '?'}
                        photo={employee?.photo}
                        className="size-10 shrink-0"
                        fallbackClassName="text-sm"
                    />
                }
                title={employee?.full_name ?? 'Unknown employee'}
                description={
                    <>
                        {employee?.position?.title ?? 'No position'}
                        {employee?.department
                            ? ` · ${employee.department.name}`
                            : ''}
                    </>
                }
                meta={
                    <>
                        <AttendanceStatusBadge status={record.status} />
                        <span className="text-xs text-muted-foreground">
                            {formatDate(record.work_date)}
                        </span>
                        {/* The shift and holiday the day was judged by, which
                            can differ from the ones in force today. */}
                        <span className="text-xs text-muted-foreground">
                            {record.scheduled_start && record.scheduled_end
                                ? `${record.schedule_name ?? 'Shift'} ${record.scheduled_start}–${record.scheduled_end}`
                                : 'No shift scheduled'}
                        </span>
                        {record.holiday && (
                            <span className="rounded bg-indigo-500/10 px-1.5 py-0.5 text-[11px] text-indigo-600 dark:text-indigo-400">
                                {record.holiday.name ?? 'Holiday'}
                                {record.holiday.type === 'special_working'
                                    ? ' · working holiday'
                                    : ''}
                            </span>
                        )}
                        {record.is_manual && (
                            <span className="rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground">
                                Recorded by hand
                            </span>
                        )}
                        {/* …and the attendance policy (ADR 0038). */}
                        {hasRecord && (
                            <span className="text-xs text-muted-foreground">
                                {detail.policy?.name
                                    ? `Judged by ${detail.policy.name}`
                                    : 'Judged by the built-in rules'}
                            </span>
                        )}
                    </>
                }
            />

            {/* What the day added up to. Outside the scrolling body, so it stays
                in view while a long trail is read. */}
            <dl className="grid shrink-0 grid-cols-3 gap-y-2 border-b border-border px-5 py-2.5 sm:flex sm:gap-y-0 sm:divide-x sm:divide-border sm:px-6">
                <Total
                    label="Worked"
                    value={formatDuration(detail.worked_minutes)}
                />
                <Total
                    label="Regular"
                    value={formatDuration(
                        detail.regular_minutes ??
                            Math.max(
                                0,
                                detail.worked_minutes - detail.overtime_minutes,
                            ),
                    )}
                />
                <Total
                    label="Break"
                    value={formatDuration(detail.break_minutes)}
                />
                <Total
                    label="Late"
                    value={formatDuration(detail.late_minutes)}
                    tone={
                        detail.late_minutes > 0
                            ? 'text-amber-600 dark:text-amber-400'
                            : undefined
                    }
                />
                <Total
                    label="Overtime"
                    value={formatDuration(detail.overtime_minutes)}
                    sub={
                        unapproved === 0
                            ? undefined
                            : unapproved === detail.overtime_minutes
                              ? 'awaiting approval'
                              : `${formatDuration(unapproved)} awaiting`
                    }
                    tone={
                        detail.overtime_minutes > 0
                            ? 'text-indigo-600 dark:text-indigo-400'
                            : undefined
                    }
                />
                {tags.map((tag) => (
                    <Total
                        key={tag.label}
                        label={tag.label}
                        value={formatDuration(tag.minutes)}
                    />
                ))}
            </dl>

            <ModalBody className="space-y-4 py-4">
                {chips.length > 0 && (
                    <ul
                        aria-label="Why the day was judged this way"
                        className="flex flex-wrap gap-1.5"
                    >
                        {chips.map((flag) => (
                            <li
                                key={flag}
                                className={cn(
                                    'rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                    FLAG_TONES[flag] === 'warn'
                                        ? 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300'
                                        : 'border-border bg-muted/50 text-muted-foreground',
                                )}
                            >
                                {FLAG_LABELS[flag]}
                            </li>
                        ))}
                    </ul>
                )}

                <section className="space-y-2">
                    <div className="flex items-baseline justify-between gap-3">
                        <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Punch trail
                        </h3>
                        <p className="text-[11px] text-muted-foreground tabular-nums">
                            {punches.length === 0
                                ? 'Nothing recorded'
                                : `${punches.length} ${punches.length === 1 ? 'punch' : 'punches'} · ${withPhoto} with a photo`}
                        </p>
                    </div>

                    <PunchTimeline punches={punches} />
                </section>

                <section className="grid gap-3 sm:grid-cols-2">
                    <Note label="Remarks" value={detail.remarks}>
                        Nothing was written about this day.
                    </Note>

                    <Note
                        label="Approval"
                        value={
                            approval === 'approved'
                                ? `Approved${detail.approver ? ` by ${detail.approver}` : ''}`
                                : approval === 'pending'
                                  ? 'Waiting for a manager to approve it'
                                  : approval === 'rejected'
                                    ? 'Rejected'
                                    : null
                        }
                        icon={
                            approval === 'approved' ? (
                                <BadgeCheck className="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                            ) : approval ? (
                                <CircleDashed className="size-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
                            ) : undefined
                        }
                    >
                        This day needs no sign-off.
                    </Note>
                </section>
            </ModalBody>

            {canManage && (
                <ModalFooter className="justify-between">
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
                                size="sm"
                                className="text-muted-foreground"
                                title="Judge this day by the employee's current schedule, attendance policy and the holiday calendar"
                                onClick={() => onReapply(record)}
                            >
                                <RefreshCw className="size-4" />
                                Re-apply rules
                            </Button>
                        )}
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
                </ModalFooter>
            )}
        </>
    );
}

/** One figure in the totals strip. */
function Total({
    label,
    value,
    sub,
    tone,
}: {
    label: string;
    value: string;
    /** A qualifier under the figure — how much of it still needs a decision. */
    sub?: string;
    tone?: string;
}) {
    return (
        <div className="min-w-0 sm:flex-1 sm:px-4 sm:first:pl-0 sm:last:pr-0">
            <dt className="truncate text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className={cn('text-sm font-semibold tabular-nums', tone)}>
                {value}
            </dd>
            {sub && (
                <dd className="truncate text-[10px] text-amber-700 dark:text-amber-300">
                    {sub}
                </dd>
            )}
        </div>
    );
}

/**
 * Something written about the day — or the plain statement that nothing was.
 * Both blocks are always drawn: an empty half of a row reads as a layout that
 * broke, where "nothing was written about this day" reads as a finding.
 */
function Note({
    label,
    value,
    icon,
    children,
}: {
    label: string;
    value: string | null;
    icon?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-lg border border-border px-3 py-2">
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            {value ? (
                <p className="mt-1 flex items-start gap-1.5 text-sm whitespace-pre-wrap">
                    {icon}
                    <span className="min-w-0">{value}</span>
                </p>
            ) : (
                <p className="mt-1 text-sm text-muted-foreground/70">
                    {children}
                </p>
            )}
        </div>
    );
}

function formatDate(date: string | null): string {
    if (!date) {
        return '—';
    }

    return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}
