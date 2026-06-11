import { Check, X } from 'lucide-react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { HALF_DAY_LABELS } from '../constants';
import type { LeaveRequest } from '../types';
import { LeaveTypeChip } from './leave-type-chip';
import { RequestStatusBadge } from './request-status-badge';

type Props = {
    request: LeaveRequest;
    canManage: boolean;
    onOpen: (request: LeaveRequest) => void;
    onApprove: (request: LeaveRequest) => void;
    onReject: (request: LeaveRequest) => void;
};

export function LeaveRequestRow({
    request,
    canManage,
    onOpen,
    onApprove,
    onReject,
}: Props) {
    const employee = request.employee;
    const isPending = request.status === 'pending';

    return (
        <div
            role="button"
            tabIndex={0}
            onClick={() => onOpen(request)}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onOpen(request);
                }
            }}
            className="flex cursor-pointer items-center gap-3 px-3 py-3 transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none sm:gap-4"
        >
            <PersonAvatar
                name={employee?.full_name ?? 'Unknown employee'}
                initials={employee?.initials ?? '?'}
                photo={employee?.photo}
                className="shrink-0"
            />

            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="truncate text-sm font-medium">
                        {employee?.full_name ?? 'Unknown employee'}
                    </span>
                    {request.type && (
                        <LeaveTypeChip
                            name={request.type.name}
                            color={request.type.color}
                            className="hidden sm:inline-flex"
                        />
                    )}
                </div>
                <p className="mt-0.5 truncate text-xs text-muted-foreground">
                    {formatRange(request)}
                    {' · '}
                    <span className="tabular-nums">
                        {request.days} day{request.days === 1 ? '' : 's'}
                    </span>
                    {request.is_half_day &&
                        request.half_day_period &&
                        ` (${HALF_DAY_LABELS[request.half_day_period]})`}
                </p>
            </div>

            <div className="flex shrink-0 items-center gap-2">
                {isPending && canManage ? (
                    <div className="hidden items-center gap-1.5 sm:flex">
                        <Button
                            size="sm"
                            variant="outline"
                            className="h-8 border-emerald-500/30 text-emerald-600 hover:bg-emerald-500/10 hover:text-emerald-600 dark:text-emerald-400"
                            onClick={(event) => {
                                event.stopPropagation();
                                onApprove(request);
                            }}
                        >
                            <Check className="size-4" />
                            Approve
                        </Button>
                        <Button
                            size="icon"
                            variant="outline"
                            className="size-8 text-muted-foreground hover:border-rose-500/30 hover:bg-rose-500/10 hover:text-rose-600"
                            aria-label="Reject"
                            onClick={(event) => {
                                event.stopPropagation();
                                onReject(request);
                            }}
                        >
                            <X className="size-4" />
                        </Button>
                    </div>
                ) : (
                    <RequestStatusBadge status={request.status} />
                )}
            </div>
        </div>
    );
}

function formatRange(request: LeaveRequest): string {
    if (!request.start_date) {
        return '—';
    }

    const fmt = (date: string) =>
        new Date(date).toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
        });

    if (!request.end_date || request.start_date === request.end_date) {
        return fmt(request.start_date);
    }

    return `${fmt(request.start_date)} – ${fmt(request.end_date)}`;
}
