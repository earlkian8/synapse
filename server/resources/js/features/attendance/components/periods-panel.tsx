import { Link, router } from '@inertiajs/react';
import {
    CalendarRange,
    CircleCheck,
    Download,
    Lock,
    LockOpen,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { PERIOD_FREQUENCY_LABELS } from '../constants';
import { attendanceRoutes } from '../routes';
import type {
    AttendancePeriod,
    PeriodChecklist,
    PeriodFrequency,
    PeriodsView,
} from '../types';

type Props = {
    periods: PeriodsView;
    canUnlock: boolean;
};

type Pending =
    | { kind: 'lock'; period: AttendancePeriod }
    | { kind: 'unlock'; period: AttendancePeriod };

/**
 * The periods attendance closes on (ADR 0039), newest first on a rail.
 *
 * Each open period says what stands between it and a lock — requests waiting,
 * days missing a clock-out, days waiting for sign-off — or that it is ready. A
 * locked one says who locked it and keeps the file payroll received. The rail
 * is the point: a locked period is a solid link in the chain, and the one still
 * open behind the others is easy to see.
 */
export function PeriodsPanel({ periods, canUnlock }: Props) {
    const [pending, setPending] = useState<Pending | null>(null);

    return (
        <div className="flex flex-col gap-4">
            <CalendarSettings settings={periods.settings} />

            {periods.items.length === 0 ? (
                <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
                    <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                        <CalendarRange className="size-5" />
                    </span>
                    <p className="text-sm font-medium">No periods yet</p>
                    <p className="max-w-sm text-sm text-muted-foreground">
                        Periods follow the calendar above. Add the current one
                        and the next to start closing attendance for payroll.
                    </p>
                    <GenerateButton label="Add the first periods" />
                </div>
            ) : (
                <ol
                    className="relative flex flex-col"
                    aria-label="Attendance periods"
                >
                    {periods.items.map((period, index) => (
                        <PeriodRow
                            key={period.hashid}
                            period={period}
                            last={index === periods.items.length - 1}
                            canUnlock={canUnlock}
                            onLock={() => setPending({ kind: 'lock', period })}
                            onUnlock={() =>
                                setPending({ kind: 'unlock', period })
                            }
                        />
                    ))}
                </ol>
            )}

            <LockDialog pending={pending} onClose={() => setPending(null)} />
        </div>
    );
}

/** The calendar periods are generated on, and when HR is reminded to lock. */
function CalendarSettings({ settings }: { settings: PeriodsView['settings'] }) {
    const [frequency, setFrequency] = useState<PeriodFrequency>(
        settings.frequency,
    );
    const [days, setDays] = useState(String(settings.reminder_days));
    const [processing, setProcessing] = useState(false);
    const dirty =
        frequency !== settings.frequency ||
        Number(days) !== settings.reminder_days;

    const save = () =>
        router.patch(
            attendanceRoutes.periodSettings,
            { frequency, reminder_days: Number(days) },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    return (
        <section className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 sm:flex-row sm:items-end sm:justify-between dark:border-sidebar-border">
            <div className="flex flex-wrap items-end gap-3">
                <div className="space-y-1.5">
                    <Label htmlFor="period-frequency">Attendance closes</Label>
                    <Select
                        value={frequency}
                        onValueChange={(value) =>
                            setFrequency(value as PeriodFrequency)
                        }
                    >
                        <SelectTrigger
                            id="period-frequency"
                            className="h-9 w-64"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.entries(PERIOD_FREQUENCY_LABELS).map(
                                ([value, label]) => (
                                    <SelectItem key={value} value={value}>
                                        {label}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="period-reminder">Remind me</Label>
                    <div className="flex items-center gap-2">
                        <Input
                            id="period-reminder"
                            type="number"
                            min={0}
                            max={14}
                            value={days}
                            onChange={(event) => setDays(event.target.value)}
                            className="h-9 w-16"
                        />
                        <span className="text-sm text-muted-foreground">
                            days before a period ends
                        </span>
                    </div>
                </div>
                {dirty && (
                    <Button
                        size="sm"
                        className="h-9"
                        onClick={save}
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        Save
                    </Button>
                )}
            </div>
            <GenerateButton label="Add periods" variant="outline" />
        </section>
    );
}

function GenerateButton({
    label,
    variant = 'default',
}: {
    label: string;
    variant?: 'default' | 'outline';
}) {
    const [processing, setProcessing] = useState(false);

    return (
        <Button
            size="sm"
            variant={variant}
            className="h-9"
            disabled={processing}
            onClick={() =>
                router.post(
                    attendanceRoutes.periodGenerate,
                    {},
                    {
                        preserveScroll: true,
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                    },
                )
            }
        >
            {processing ? <Spinner /> : <Plus className="size-4" />}
            {label}
        </Button>
    );
}

const PHASE_LABELS: Record<AttendancePeriod['phase'], string> = {
    past: 'Ended',
    current: 'Current',
    upcoming: 'Upcoming',
};

function PeriodRow({
    period,
    last,
    canUnlock,
    onLock,
    onUnlock,
}: {
    period: AttendancePeriod;
    last: boolean;
    canUnlock: boolean;
    onLock: () => void;
    onUnlock: () => void;
}) {
    const locked = period.status === 'locked';

    return (
        <li className="relative grid grid-cols-[2rem_minmax(0,1fr)] gap-3">
            {/* The rail: a solid link for a locked period, an open ring for the rest. */}
            <div className="relative flex justify-center" aria-hidden>
                {!last && (
                    <span
                        className={cn(
                            'absolute top-8 bottom-0 w-px',
                            locked ? 'bg-[#0ABFBF]/50' : 'bg-border',
                        )}
                    />
                )}
                <span
                    className={cn(
                        'relative mt-4 flex size-7 items-center justify-center rounded-full border',
                        locked
                            ? 'border-[#0ABFBF] bg-[#0ABFBF] text-white'
                            : period.phase === 'current'
                              ? 'border-[#0ABFBF] bg-background text-[#0a8b91] dark:text-[#0ABFBF]'
                              : 'border-border bg-background text-muted-foreground',
                    )}
                >
                    {locked ? (
                        <Lock className="size-3.5" />
                    ) : (
                        <span className="size-1.5 rounded-full bg-current" />
                    )}
                </span>
            </div>

            <div className="mb-3 flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border">
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <h3 className="text-base font-semibold tracking-tight tabular-nums">
                            {period.label}
                        </h3>
                        <span className="text-xs text-muted-foreground">
                            {locked ? 'Locked' : PHASE_LABELS[period.phase]}
                        </span>
                    </div>

                    {locked ? (
                        <p className="text-xs text-muted-foreground">
                            {period.locked_by
                                ? `Locked by ${period.locked_by}`
                                : 'Locked'}
                            {period.locked_at
                                ? ` on ${formatDay(period.locked_at)}`
                                : ''}
                            {period.lock_note ? ` — “${period.lock_note}”` : ''}
                        </p>
                    ) : (
                        period.checklist && (
                            <Checklist
                                checklist={period.checklist}
                                upcoming={period.phase === 'upcoming'}
                            />
                        )
                    )}

                    {!locked && period.unlocked_at && (
                        <p className="text-xs text-muted-foreground">
                            Reopened
                            {period.unlocked_by
                                ? ` by ${period.unlocked_by}`
                                : ''}{' '}
                            on {formatDay(period.unlocked_at)}
                            {period.unlock_reason
                                ? ` — “${period.unlock_reason}”`
                                : ''}
                        </p>
                    )}
                </div>

                <div className="flex shrink-0 items-center gap-2">
                    {locked ? (
                        <>
                            {period.has_export && (
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={attendanceRoutes.periodExport(
                                            period.hashid,
                                        )}
                                    >
                                        <Download className="size-4" />
                                        Payroll file
                                    </a>
                                </Button>
                            )}
                            {canUnlock && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="text-muted-foreground"
                                    onClick={onUnlock}
                                >
                                    <LockOpen className="size-4" />
                                    Unlock
                                </Button>
                            )}
                        </>
                    ) : (
                        period.phase !== 'upcoming' && (
                            <Button
                                size="sm"
                                variant={
                                    period.checklist?.clear
                                        ? 'default'
                                        : 'outline'
                                }
                                onClick={onLock}
                            >
                                <Lock className="size-4" />
                                Lock
                            </Button>
                        )
                    )}
                </div>
            </div>
        </li>
    );
}

/** What is still open, each item a count; or that nothing is. */
function Checklist({
    checklist,
    upcoming,
}: {
    checklist: PeriodChecklist;
    upcoming: boolean;
}) {
    if (upcoming) {
        return (
            <p className="text-xs text-muted-foreground">Not started yet.</p>
        );
    }

    if (checklist.clear) {
        return (
            <p className="flex items-center gap-1.5 text-xs text-emerald-700 dark:text-emerald-400">
                <CircleCheck className="size-3.5" />
                Nothing open — ready to lock.
            </p>
        );
    }

    const items = [
        {
            count: checklist.pending_requests,
            label: (n: number) =>
                `${n} ${n === 1 ? 'request' : 'requests'} waiting`,
            href: `${attendanceRoutes.index}?tab=requests`,
        },
        {
            count: checklist.incomplete_days,
            label: (n: number) =>
                `${n} ${n === 1 ? 'day' : 'days'} missing a clock-out`,
        },
        {
            count: checklist.pending_sign_offs,
            label: (n: number) =>
                `${n} ${n === 1 ? 'day' : 'days'} awaiting sign-off`,
        },
    ].filter((item) => item.count > 0);

    return (
        <ul
            className="flex flex-wrap gap-1.5"
            aria-label="Still open in this period"
        >
            {items.map((item) => (
                <li key={item.label(item.count)}>
                    {item.href ? (
                        <Link
                            href={item.href}
                            className="inline-flex rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-[11px] font-medium text-amber-700 hover:bg-amber-500/20 dark:text-amber-300"
                        >
                            {item.label(item.count)}
                        </Link>
                    ) : (
                        <span className="inline-flex rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:text-amber-300">
                            {item.label(item.count)}
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

/** Lock (with the checklist, and a reason when it is not clear) or unlock (always with a reason). */
function LockDialog({
    pending,
    onClose,
}: {
    pending: Pending | null;
    onClose: () => void;
}) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const period = pending?.period;
    const unlocking = pending?.kind === 'unlock';
    const clear = period?.checklist?.clear ?? true;
    const needsReason = unlocking || !clear;

    const close = () => {
        setReason('');
        setError(undefined);
        onClose();
    };

    const submit = () => {
        if (!period) {
            return;
        }

        router.post(
            unlocking
                ? attendanceRoutes.periodUnlock(period.hashid)
                : attendanceRoutes.periodLock(period.hashid),
            { reason: reason || null },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onError: (errors) => setError(errors.reason),
                onSuccess: () => close(),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog
            open={pending !== null}
            onOpenChange={(open) => !open && close()}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {unlocking ? 'Unlock' : 'Lock'} {period?.label}?
                    </DialogTitle>
                    <DialogDescription>
                        {unlocking
                            ? 'Its days can be changed again — punches, corrections, requests. The payroll file from the lock is kept.'
                            : 'Nothing about its days can change until it is unlocked. The payroll summary is saved with it, exactly as it stands now.'}
                    </DialogDescription>
                </DialogHeader>

                {!unlocking && period?.checklist && !clear && (
                    <div className="space-y-2 rounded-lg border border-amber-500/30 bg-amber-500/[0.07] px-3 py-2.5">
                        <p className="text-sm font-medium text-amber-800 dark:text-amber-200">
                            Still open in this period
                        </p>
                        <Checklist
                            checklist={period.checklist}
                            upcoming={false}
                        />
                        <p className="text-xs text-muted-foreground">
                            Locking now freezes them as they are.
                        </p>
                    </div>
                )}

                {needsReason && (
                    <div className="space-y-1.5">
                        <Label htmlFor="period-reason">
                            {unlocking ? 'Why reopen it' : 'Why lock it anyway'}
                        </Label>
                        <textarea
                            id="period-reason"
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={2}
                            placeholder={
                                unlocking
                                    ? 'A late correction from the site.'
                                    : 'Payroll cut-off is today.'
                            }
                            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                        />
                        <InputError message={error} />
                        <p className="text-xs text-muted-foreground">
                            Kept in the activity log.
                        </p>
                    </div>
                )}

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={close}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={
                            processing ||
                            (needsReason && reason.trim().length < 3)
                        }
                    >
                        {processing && <Spinner />}
                        {unlocking ? 'Unlock period' : 'Lock period'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function formatDay(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}
