import { router } from '@inertiajs/react';
import {
    CalendarRange,
    Check,
    Pencil,
    Trash2,
    UserRound,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
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

export function ReviewRequestSheet({
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
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-lg"
            >
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
            </SheetContent>
        </Sheet>
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
                    <RequestStatusBadge status={request.status} />
                </div>
            </SheetHeader>

            <div className="flex-1 space-y-6 px-6 py-6">
                <div className="grid grid-cols-2 gap-3">
                    <Meta label="Leave type">
                        {request.type ? (
                            <LeaveTypeChip
                                name={request.type.name}
                                color={request.type.color}
                            />
                        ) : (
                            '—'
                        )}
                    </Meta>
                    <Meta label="Duration">
                        <span className="tabular-nums">
                            {request.days} day{request.days === 1 ? '' : 's'}
                        </span>
                        {request.is_half_day && request.half_day_period
                            ? ` · ${HALF_DAY_LABELS[request.half_day_period]}`
                            : ''}
                    </Meta>
                    <Meta label="Dates" full>
                        <span className="inline-flex items-center gap-1.5">
                            <CalendarRange className="size-3.5 text-muted-foreground" />
                            {formatRange(request)}
                        </span>
                    </Meta>
                </div>

                {request.reason && (
                    <Block label="Reason">{request.reason}</Block>
                )}

                {/* Balance impact for this type/year. */}
                {balance && (
                    <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <div className="mb-2 flex items-center justify-between text-sm">
                            <span className="font-medium">
                                {balance.name} balance
                            </span>
                            <span className="text-muted-foreground tabular-nums">
                                {balance.remaining} of {balance.entitled} left
                            </span>
                        </div>
                        <BalanceMeter balance={balance} />
                        <div className="mt-2 flex items-center gap-3 text-[11px] text-muted-foreground">
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
                    </div>
                )}

                {detail.review_note && (
                    <Block label="Review note">{detail.review_note}</Block>
                )}

                <div className="space-y-1.5 text-xs text-muted-foreground">
                    {detail.filer && (
                        <p className="inline-flex items-center gap-1.5">
                            <UserRound className="size-3.5" />
                            Filed by {detail.filer}
                            {request.created_human
                                ? ` · ${request.created_human}`
                                : ''}
                        </p>
                    )}
                    {detail.reviewer && request.reviewed_at && (
                        <p className="inline-flex items-center gap-1.5">
                            <Check className="size-3.5" />
                            Reviewed by {detail.reviewer} ·{' '}
                            {formatDateTime(request.reviewed_at)}
                        </p>
                    )}
                </div>

                {/* Review note input — only when acting on a pending request. */}
                {isPending && canManage && (
                    <div>
                        <label className="mb-1.5 block text-sm font-medium">
                            Review note{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </label>
                        <textarea
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                            rows={2}
                            placeholder="Add a note for the employee…"
                            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                        />
                    </div>
                )}
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border px-6 py-4">
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
            </div>
        </div>
    );
}

function BalanceMeter({ balance }: { balance: BalanceSnapshot }) {
    const total = Math.max(balance.entitled, balance.used + balance.pending, 1);
    const usedPct = (balance.used / total) * 100;
    const pendingPct = (balance.pending / total) * 100;

    return (
        <div className="flex h-2 w-full overflow-hidden rounded-full bg-muted">
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

function Meta({
    label,
    full = false,
    children,
}: {
    label: string;
    full?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div
            className={
                full
                    ? 'col-span-2 rounded-lg border border-sidebar-border/70 bg-card p-3 dark:border-sidebar-border'
                    : 'rounded-lg border border-sidebar-border/70 bg-card p-3 dark:border-sidebar-border'
            }
        >
            <p className="text-xs text-muted-foreground">{label}</p>
            <div className="mt-1 text-sm font-medium">{children}</div>
        </div>
    );
}

function Block({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <p className="mb-1 text-xs font-medium text-muted-foreground">
                {label}
            </p>
            <p className="rounded-lg bg-muted/50 px-3 py-2 text-sm whitespace-pre-wrap">
                {children}
            </p>
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
            year: 'numeric',
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
