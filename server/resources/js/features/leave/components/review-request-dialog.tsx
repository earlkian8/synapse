import { router } from '@inertiajs/react';
import { Check, Pencil, Trash2, X } from 'lucide-react';
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
import { HALF_DAY_LABELS } from '../constants';
import { leaveRoutes } from '../routes';
import type { BalanceSnapshot, LeaveRequest } from '../types';
import { LeaveTypeChip } from './leave-type-chip';
import { RequestStatusBadge } from './request-status-badge';

type Props = {
    request: LeaveRequest | null;
    canRequest: boolean;
    canManage: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onEdit: (request: LeaveRequest) => void;
    onCancel: (request: LeaveRequest) => void;
    onDelete: (request: LeaveRequest) => void;
};

/**
 * One leave request, opened in the middle of the screen.
 *
 * An approver reads it in one order — who is asking, for what and when, whether
 * they have the days, and why — so that is the order it is laid out in: the
 * person in the header, the ask in a strip that stays put beneath it, and the
 * balance as the body's first and largest thing, because it is the only part of
 * the decision that is a number rather than a judgement.
 *
 * Every block is drawn whether or not it has content. A request with no reason
 * given is a fact worth stating; a gap where the reason would be is not.
 */
export function ReviewRequestDialog({
    request,
    canRequest,
    canManage,
    open,
    onOpenChange,
    onEdit,
    onCancel,
    onDelete,
}: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                {request && (
                    <Body
                        key={request.id}
                        request={request}
                        canRequest={canRequest}
                        canManage={canManage}
                        onEdit={onEdit}
                        onCancel={onCancel}
                        onDelete={onDelete}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function Body({
    request,
    canRequest,
    canManage,
    onEdit,
    onCancel,
    onDelete,
    onDone,
}: {
    request: LeaveRequest;
    canRequest: boolean;
    canManage: boolean;
    onEdit: (request: LeaveRequest) => void;
    onCancel: (request: LeaveRequest) => void;
    onDelete: (request: LeaveRequest) => void;
    onDone: () => void;
}) {
    const employee = request.employee;
    const isPending = request.status === 'pending';

    const [note, setNote] = useState('');
    const [processing, setProcessing] = useState(false);
    const [detail, setDetail] = useState<LeaveRequest>(request);
    const balance = detail.balance;

    // Enrich with the balance snapshot (and filer/reviewer) the list doesn't carry.
    useEffect(() => {
        let active = true;

        fetch(leaveRoutes.show(request.hashid), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((payload) => {
                if (active && payload?.data) {
                    setDetail(payload.data as LeaveRequest);
                }
            })
            .catch(() => undefined);

        return () => {
            active = false;
        };
    }, [request.hashid]);

    const review = (action: 'approve' | 'reject') => {
        router.patch(
            leaveRoutes.review(request.hashid),
            { action, review_note: note || null },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onDone(),
            },
        );
    };

    const duration = `${request.days} day${request.days === 1 ? '' : 's'}${
        request.is_half_day && request.half_day_period
            ? ` · ${HALF_DAY_LABELS[request.half_day_period]}`
            : ''
    }`;

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
                meta={<RequestStatusBadge status={request.status} />}
            />

            {/* The ask itself, outside the scrolling body so it stays in view
                while the reason and the history are read. */}
            <dl className="grid shrink-0 grid-cols-2 gap-x-4 gap-y-2 border-b border-border px-5 py-2.5 sm:flex sm:gap-y-0 sm:divide-x sm:divide-border sm:px-6">
                <Fact label="Leave type">
                    {request.type ? (
                        <LeaveTypeChip
                            name={request.type.name}
                            color={request.type.color}
                        />
                    ) : (
                        <span className="text-muted-foreground">Not set</span>
                    )}
                </Fact>
                <Fact label="Dates" grow>
                    {formatRange(request)}
                </Fact>
                <Fact label="Charged">
                    <span className="tabular-nums">{duration}</span>
                </Fact>
            </dl>

            <ModalBody className="space-y-4 py-4">
                {balance ? (
                    <section className="rounded-lg border border-border px-3.5 py-3">
                        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                            <p className="text-sm font-medium">
                                {balance.name} balance
                            </p>
                            <p className="text-xs text-muted-foreground tabular-nums">
                                {balance.remaining} of {balance.entitled} days
                                left
                            </p>
                        </div>
                        <BalanceMeter balance={balance} />
                        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
                            <Legend
                                color="#0ABFBF"
                                label={`${balance.used} used`}
                            />
                            <Legend
                                color="#F59E0B"
                                label={`${balance.pending} pending`}
                            />
                            <Legend
                                color="var(--muted)"
                                label={`${Math.max(balance.remaining, 0)} left`}
                            />
                        </div>
                    </section>
                ) : (
                    <p className="rounded-lg border border-dashed border-border px-3.5 py-3 text-sm text-muted-foreground">
                        No balance is tracked for this leave type, so this
                        request is not charged against an entitlement.
                    </p>
                )}

                <div className="grid gap-3 sm:grid-cols-2">
                    <Block label="Reason given" value={request.reason}>
                        No reason was given.
                    </Block>
                    <Block label="Review note" value={detail.review_note}>
                        {isPending
                            ? 'Nothing yet — add one below.'
                            : 'No note was left.'}
                    </Block>
                </div>

                <div className="space-y-1 text-xs text-muted-foreground">
                    <p>
                        {detail.filer ? `Filed by ${detail.filer}` : 'Filed'}
                        {request.created_human
                            ? ` · ${request.created_human}`
                            : ''}
                    </p>
                    <p>
                        {detail.reviewer && request.reviewed_at
                            ? `Reviewed by ${detail.reviewer} · ${formatDateTime(request.reviewed_at)}`
                            : 'Not reviewed yet.'}
                    </p>
                </div>

                {isPending && canManage && (
                    <div className="space-y-1.5">
                        <Label htmlFor="review-note">
                            Add a note{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional — the employee sees it)
                            </span>
                        </Label>
                        <textarea
                            id="review-note"
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                            rows={2}
                            placeholder="Why this is approved or turned down…"
                            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                        />
                    </div>
                )}
            </ModalBody>

            <ModalFooter className="justify-between">
                <div className="flex items-center gap-2">
                    {isPending && canRequest && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => onEdit(request)}
                            disabled={processing}
                        >
                            <Pencil className="size-4" />
                            Edit
                        </Button>
                    )}
                    {(isPending || request.status === 'approved') &&
                        canRequest && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-muted-foreground"
                                onClick={() => onCancel(request)}
                                disabled={processing}
                            >
                                Cancel leave
                            </Button>
                        )}
                    {canManage && (
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8 text-muted-foreground hover:text-destructive"
                            aria-label="Delete request"
                            onClick={() => onDelete(request)}
                            disabled={processing}
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    )}
                </div>

                {isPending && canManage && (
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
        </>
    );
}

/** One term of the ask, in the strip under the header. */
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
            className={`min-w-0 sm:px-4 sm:first:pl-0 sm:last:pr-0 ${grow ? 'sm:flex-1' : ''}`}
        >
            <dt className="truncate text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="truncate text-sm font-medium">{children}</dd>
        </div>
    );
}

function BalanceMeter({ balance }: { balance: BalanceSnapshot }) {
    const total = Math.max(balance.entitled, balance.used + balance.pending, 1);
    const usedPct = (balance.used / total) * 100;
    const pendingPct = (balance.pending / total) * 100;

    return (
        <div className="mt-2 flex h-2 w-full overflow-hidden rounded-full bg-muted">
            <div
                className="h-full bg-[#0ABFBF]"
                style={{ width: `${usedPct}%` }}
            />
            <div
                className="h-full bg-amber-500"
                style={{ width: `${pendingPct}%` }}
            />
        </div>
    );
}

function Legend({ color, label }: { color: string; label: string }) {
    return (
        <span className="inline-flex items-center gap-1">
            <span
                className="size-2 rounded-full"
                style={{ backgroundColor: color }}
            />
            {label}
        </span>
    );
}

/**
 * Something written about the request — or the plain statement that nothing
 * was. Both halves of the row are always drawn, so the layout does not change
 * shape depending on how much anybody typed.
 */
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

function formatRange(request: LeaveRequest): string {
    if (!request.start_date) {
        return '—';
    }

    const fmt = (date: string) =>
        new Date(date).toLocaleDateString(undefined, {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
        });

    if (!request.end_date || request.start_date === request.end_date) {
        return fmt(request.start_date);
    }

    return `${fmt(request.start_date)} → ${fmt(request.end_date)}`;
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}
