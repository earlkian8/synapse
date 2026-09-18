import { router } from '@inertiajs/react';
import { ArrowRight, Check, Lock, Paperclip, X } from 'lucide-react';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';
import { cn } from '@/lib/utils';
import {
    CORRECTION_FIELDS,
    clockReading,
    describeRequest,
    formatClockFace,
    formatDateRange,
    formatDuration,
    REQUEST_TYPE_LABELS,
} from '../constants';
import { attendanceRoutes } from '../routes';
import type { AttendanceRequestItem, RequestPunch } from '../types';
import { RequestStatusBadge } from './request-status-badge';

type Props = {
    request: AttendanceRequestItem | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * One attendance request, opened in the middle of the screen (ADR 0039).
 *
 * A reviewer is being asked to change the record, so the record comes first:
 * a correction is drawn as the day's punches beside the ones asked for, with
 * what would change marked; overtime shows how much was actually worked past the
 * shift against how much is asked. Only then the reason, and the decision.
 *
 * The same modal serves the employee looking at their own request — they see
 * the comparison and the outcome, and can withdraw it while it is pending.
 */
export function RequestReviewDialog({ request, open, onOpenChange }: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                {request && (
                    <Body
                        key={request.hashid}
                        request={request}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function Body({
    request,
    onDone,
}: {
    request: AttendanceRequestItem;
    onDone: () => void;
}) {
    const [detail, setDetail] = useState<AttendanceRequestItem>(request);
    const [note, setNote] = useState('');
    const [processing, setProcessing] = useState(false);
    const employee = detail.employee ?? request.employee;

    // The day as it stands and the punches a correction replaced — the list
    // does not carry them.
    useEffect(() => {
        let active = true;

        fetch(attendanceRoutes.requestShow(request.hashid), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((payload) => {
                if (active && payload?.data) {
                    setDetail(payload.data as AttendanceRequestItem);
                }
            })
            .catch(() => undefined);

        return () => {
            active = false;
        };
    }, [request.hashid]);

    const options = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onSuccess: () => onDone(),
    };

    const review = (action: 'approve' | 'reject') =>
        router.patch(
            attendanceRoutes.requestReview(request.hashid),
            { action, review_note: note || null },
            options,
        );

    const cancel = () =>
        router.patch(
            attendanceRoutes.requestCancel(request.hashid),
            {},
            options,
        );

    const isPending = detail.status === 'pending';

    return (
        <>
            <ModalHeader
                className="py-3.5"
                icon={
                    <PersonAvatar
                        name={employee?.full_name ?? 'Employee'}
                        initials={employee?.initials ?? '?'}
                        photo={employee?.photo}
                        className="size-10 shrink-0"
                        fallbackClassName="text-sm"
                    />
                }
                title={employee?.full_name ?? 'Your request'}
                description={
                    employee
                        ? `${employee.position?.title ?? 'No position'}${employee.department ? ` · ${employee.department.name}` : ''}`
                        : undefined
                }
                meta={<RequestStatusBadge status={detail.status} />}
            />

            {/* The ask, outside the scrolling body so it stays in view. */}
            <dl className="grid shrink-0 grid-cols-2 gap-x-4 gap-y-2 border-b border-border px-5 py-2.5 sm:flex sm:gap-y-0 sm:divide-x sm:divide-border sm:px-6">
                <Fact label="Request">{REQUEST_TYPE_LABELS[detail.type]}</Fact>
                <Fact
                    label={
                        detail.start_date === detail.end_date ? 'Day' : 'Days'
                    }
                >
                    {formatDateRange(detail.start_date, detail.end_date)}
                </Fact>
                <Fact label="Asked for" grow>
                    {describeRequest(detail.type, detail.payload)}
                </Fact>
            </dl>

            <ModalBody className="space-y-4 py-4">
                {detail.locked_period && isPending && (
                    <p className="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-200">
                        <Lock className="mt-0.5 size-4 shrink-0" />
                        <span>
                            {detail.locked_period} is locked, so this request
                            cannot be decided until the period is unlocked.
                        </span>
                    </p>
                )}

                {detail.type === 'correction' && (
                    <CorrectionDiff request={detail} />
                )}
                {detail.type === 'overtime' && (
                    <OvertimeMeter request={detail} />
                )}
                {(detail.type === 'official_business' ||
                    detail.type === 'remote_work') && (
                    <p className="rounded-lg border border-border px-3.5 py-3 text-sm text-muted-foreground">
                        {detail.type === 'official_business'
                            ? 'Approved, each working day in the range counts as a full day at work: nothing late, nothing short, even with no punches.'
                            : 'Approved, the days are marked as worked remotely. Clocking in and out is still required.'}
                    </p>
                )}

                <div className="grid gap-3 sm:grid-cols-2">
                    <Block label="Reason given" value={detail.reason}>
                        No reason was given.
                    </Block>
                    <Block label="Review note" value={detail.review_note}>
                        {isPending ? 'Nothing yet.' : 'No note was left.'}
                    </Block>
                </div>

                {detail.attachment && (
                    <a
                        href={detail.attachment}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1.5 text-sm font-medium text-[#0a8b91] hover:underline dark:text-[#0ABFBF]"
                    >
                        <Paperclip className="size-4" />
                        Open the attachment
                    </a>
                )}

                <div className="space-y-1 text-xs text-muted-foreground">
                    <p>
                        {detail.requester
                            ? `Filed by ${detail.requester}`
                            : 'Filed'}
                        {detail.created_human
                            ? ` · ${detail.created_human}`
                            : ''}
                    </p>
                    <p>
                        {detail.reviewer && detail.reviewed_at
                            ? `${detail.status === 'approved' ? 'Approved' : 'Rejected'} by ${detail.reviewer}`
                            : isPending
                              ? 'Not reviewed yet.'
                              : null}
                    </p>
                </div>

                {detail.can.review && (
                    <div className="space-y-1.5">
                        <Label htmlFor="request-review-note">
                            Add a note{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional — the employee sees it)
                            </span>
                        </Label>
                        <textarea
                            id="request-review-note"
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                            rows={2}
                            placeholder="Why this is approved or turned down…"
                            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                        />
                    </div>
                )}
            </ModalBody>

            {(detail.can.review || detail.can.cancel) && (
                <ModalFooter className="justify-between">
                    <div>
                        {detail.can.cancel && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-muted-foreground"
                                onClick={cancel}
                                disabled={processing}
                            >
                                Cancel request
                            </Button>
                        )}
                    </div>

                    {detail.can.review && (
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                className="border-rose-500/30 text-rose-600 hover:bg-rose-500/10 hover:text-rose-600 dark:text-rose-400"
                                onClick={() => review('reject')}
                                disabled={processing}
                            >
                                <X className="size-4" />
                                Reject
                            </Button>
                            <Button
                                size="sm"
                                className="bg-emerald-600 text-white hover:bg-emerald-600/90"
                                onClick={() => review('approve')}
                                disabled={processing}
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <Check className="size-4" />
                                )}
                                Approve
                            </Button>
                        </div>
                    )}
                </ModalFooter>
            )}
        </>
    );
}

/**
 * The day's punches beside the ones asked for, one row per punch the day shows.
 * A row the request changes reads "was → becomes"; a row it leaves alone reads
 * as it is, so the reviewer sees at a glance how much of the day moves.
 *
 * Once a correction is approved the day already shows the new punches, so the
 * "was" column reads from the punches it replaced.
 */
function CorrectionDiff({ request }: { request: AttendanceRequestItem }) {
    const timeZone = useOrganizationTimeZone();
    const approved = request.status === 'approved';
    const current = readings(request.day?.punches ?? [], timeZone);
    const replaced = readings(request.replaced ?? [], timeZone);
    const changeCount = CORRECTION_FIELDS.filter(
        ({ key }) => request.payload[key],
    ).length;

    return (
        <section className="overflow-hidden rounded-lg border border-border">
            <div className="flex items-baseline justify-between gap-3 border-b border-border bg-muted/40 px-3.5 py-2">
                <h3 className="text-sm font-medium">The day's punches</h3>
                <p className="text-xs text-muted-foreground tabular-nums">
                    {changeCount}{' '}
                    {changeCount === 1 ? 'punch changes' : 'punches change'}
                    {request.day ? '' : ' · nothing was recorded'}
                </p>
            </div>
            <ul className="divide-y divide-border">
                {CORRECTION_FIELDS.map(({ key, label, punch }) => {
                    const asked = request.payload[key] ?? null;
                    const was =
                        approved && asked
                            ? (replaced[punch] ?? null)
                            : (current[punch] ?? null);
                    const changes = Boolean(asked) && asked !== was;

                    return (
                        <li
                            key={key}
                            className={cn(
                                'grid grid-cols-[6.5rem_minmax(0,1fr)] items-center gap-3 px-3.5 py-2 text-sm',
                                changes && 'bg-[#0ABFBF]/[0.06]',
                            )}
                        >
                            <span className="text-muted-foreground">
                                {label}
                            </span>
                            {changes ? (
                                <span className="flex items-center gap-2 tabular-nums">
                                    <span
                                        className={cn(
                                            'text-muted-foreground',
                                            was &&
                                                'line-through decoration-muted-foreground/60',
                                        )}
                                    >
                                        {was
                                            ? formatClockFace(was)
                                            : 'Not punched'}
                                    </span>
                                    <ArrowRight className="size-3.5 shrink-0 text-[#0a8b91] dark:text-[#0ABFBF]" />
                                    <span className="font-semibold text-[#0a8b91] dark:text-[#0ABFBF]">
                                        {formatClockFace(asked)}
                                    </span>
                                </span>
                            ) : (
                                <span className="tabular-nums">
                                    {was ? (
                                        formatClockFace(was)
                                    ) : (
                                        <span className="text-muted-foreground/70">
                                            Not punched
                                        </span>
                                    )}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

/**
 * How much overtime the day actually has, against how much is asked for.
 * Approval grants the smaller of the two, so the bar shows exactly what an
 * approval would put in the approved bucket.
 */
function OvertimeMeter({ request }: { request: AttendanceRequestItem }) {
    const asked = request.payload.minutes ?? 0;
    const worked = request.day?.overtime_minutes ?? null;

    if (worked === null) {
        return (
            <p className="rounded-lg border border-border px-3.5 py-3 text-sm text-muted-foreground">
                {request.payload.pre_approval
                    ? `Asked in advance. Approved, up to ${formatDuration(asked)} of overtime counts as approved once the day is worked.`
                    : 'Nothing has been recorded for this day yet. Approved, up to the asked overtime counts once it is.'}
            </p>
        );
    }

    const granted = Math.min(asked, worked);
    const scale = Math.max(asked, worked, 1);

    return (
        <section className="rounded-lg border border-border px-3.5 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <p className="text-sm font-medium">Overtime on the day</p>
                <p className="text-xs text-muted-foreground tabular-nums">
                    {formatDuration(worked)} worked past the shift ·{' '}
                    {formatDuration(asked)} asked
                </p>
            </div>
            <div
                className="relative mt-2 h-2 w-full overflow-hidden rounded-full bg-muted"
                role="img"
                aria-label={`${formatDuration(granted)} of ${formatDuration(worked)} would be approved`}
            >
                <div
                    className="absolute inset-y-0 left-0 bg-indigo-500/25"
                    style={{ width: `${(worked / scale) * 100}%` }}
                />
                <div
                    className="absolute inset-y-0 left-0 bg-indigo-500"
                    style={{ width: `${(granted / scale) * 100}%` }}
                />
            </div>
            <p className="mt-2 text-xs text-muted-foreground">
                {asked > worked
                    ? `More is asked than was worked; approving grants the ${formatDuration(worked)} actually worked.`
                    : `Approving puts ${formatDuration(granted)} in approved overtime${worked > granted ? `; the other ${formatDuration(worked - granted)} stays unapproved` : ''}.`}
            </p>
        </section>
    );
}

/** The punch a correction field compares against, as "HH:MM" on the organisation's clock. */
function readings(
    punches: RequestPunch[],
    timeZone: string | undefined,
): Partial<Record<RequestPunch['type'], string>> {
    const out: Partial<Record<RequestPunch['type'], string>> = {};

    for (const punch of punches) {
        const reading = clockReading(punch.punched_at, timeZone);

        // The first clock-in and break, the last clock-out — as the server picks.
        if (punch.type === 'clock_out' || !out[punch.type]) {
            out[punch.type] = reading;
        }
    }

    return out;
}

function Fact({
    label,
    grow = false,
    children,
}: {
    label: string;
    grow?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div
            className={cn(
                'min-w-0 sm:px-4 sm:first:pl-0 sm:last:pr-0',
                grow && 'col-span-2 sm:col-span-1 sm:flex-1',
            )}
        >
            <dt className="truncate text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="truncate text-sm font-medium">{children}</dd>
        </div>
    );
}

function Block({
    label,
    value,
    children,
}: {
    label: string;
    value: string | null | undefined;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-lg border border-border px-3 py-2">
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            {value ? (
                <p className="mt-1 text-sm whitespace-pre-wrap">{value}</p>
            ) : (
                <p className="mt-1 text-sm text-muted-foreground/70">
                    {children}
                </p>
            )}
        </div>
    );
}
