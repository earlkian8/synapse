import { CalendarDays, Check, Eye, X } from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    RowMenuTrigger,
    rowOpens,
    TableCard,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { HALF_DAY_LABELS } from '../constants';
import type { LeaveRequest } from '../types';
import { LeaveTypeChip } from './leave-type-chip';
import { RequestStatusBadge } from './request-status-badge';

type Props = {
    requests: LeaveRequest[];
    canManage: boolean;
    filtered: boolean;
    onOpen: (request: LeaveRequest) => void;
    onApprove: (request: LeaveRequest) => void;
    onReject: (request: LeaveRequest) => void;
};

/**
 * Leave requests: who, what kind, when and for how long, and where each stands.
 * A row opens the request; a pending one can be approved or rejected right from
 * its row.
 */
export function LeaveRequestsTable({
    requests,
    canManage,
    filtered,
    onOpen,
    onApprove,
    onReject,
}: Props) {
    return (
        <TableCard>
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <TableHead>Employee</TableHead>
                        <TableHead>Leave type</TableHead>
                        <TableHead>Dates</TableHead>
                        <TableHead className="text-right">Days</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Filed</TableHead>
                        <TableHead className="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {requests.length === 0 && (
                        <EmptyTableRow
                            colSpan={7}
                            icon={CalendarDays}
                            title="No leave requests here"
                            description={
                                filtered
                                    ? 'Try another status, type or department, or clear the search.'
                                    : 'File one for an employee to get started.'
                            }
                        />
                    )}

                    {requests.map((request) => {
                        const employee = request.employee;
                        const pending = request.status === 'pending';

                        return (
                            <TableRow
                                key={request.id}
                                {...rowOpens(() => onOpen(request))}
                            >
                                <TableCell>
                                    <div className="flex min-w-0 items-center gap-2.5">
                                        <PersonAvatar
                                            name={
                                                employee?.full_name ??
                                                'Unknown employee'
                                            }
                                            initials={employee?.initials ?? '?'}
                                            photo={employee?.photo}
                                            className="size-8"
                                            fallbackClassName="text-[11px]"
                                        />
                                        <div className="min-w-0">
                                            <p className="max-w-52 truncate text-sm font-medium">
                                                {employee?.full_name ??
                                                    'Unknown employee'}
                                            </p>
                                            <p className="max-w-52 truncate text-xs text-muted-foreground">
                                                {employee?.department?.name ??
                                                    employee?.employee_no ??
                                                    '—'}
                                            </p>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    {request.type ? (
                                        <LeaveTypeChip
                                            name={request.type.name}
                                            color={request.type.color}
                                        />
                                    ) : (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-sm">
                                    {formatRange(request)}
                                    {request.is_half_day &&
                                        request.half_day_period && (
                                            <span className="block text-xs text-muted-foreground">
                                                {
                                                    HALF_DAY_LABELS[
                                                        request.half_day_period
                                                    ]
                                                }
                                            </span>
                                        )}
                                </TableCell>
                                <TableCell className="text-right text-sm font-medium tabular-nums">
                                    {request.days}
                                </TableCell>
                                <TableCell>
                                    <RequestStatusBadge
                                        status={request.status}
                                    />
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {request.created_human ?? '—'}
                                </TableCell>
                                <TableCell className="text-right">
                                    <div className="inline-flex items-center gap-1">
                                        {pending && canManage && (
                                            <>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-7 border-emerald-500/30 px-2 text-emerald-600 hover:bg-emerald-500/10 hover:text-emerald-600 dark:text-emerald-400"
                                                    onClick={() =>
                                                        onApprove(request)
                                                    }
                                                >
                                                    <Check className="size-3.5" />
                                                    Approve
                                                </Button>
                                                <Button
                                                    size="icon"
                                                    variant="outline"
                                                    className="size-7 text-muted-foreground hover:border-rose-500/30 hover:bg-rose-500/10 hover:text-rose-600"
                                                    aria-label={`Reject leave for ${employee?.full_name ?? 'this employee'}`}
                                                    onClick={() =>
                                                        onReject(request)
                                                    }
                                                >
                                                    <X className="size-3.5" />
                                                </Button>
                                            </>
                                        )}
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <RowMenuTrigger
                                                    label={`Actions for ${employee?.full_name ?? 'this request'}`}
                                                />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent
                                                align="end"
                                                className="w-44"
                                            >
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        onOpen(request)
                                                    }
                                                >
                                                    <Eye className="size-4" />
                                                    Open request
                                                </DropdownMenuItem>
                                                {pending && canManage && (
                                                    <>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                onApprove(
                                                                    request,
                                                                )
                                                            }
                                                        >
                                                            <Check className="size-4" />
                                                            Approve
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                onReject(
                                                                    request,
                                                                )
                                                            }
                                                        >
                                                            <X className="size-4" />
                                                            Reject
                                                        </DropdownMenuItem>
                                                    </>
                                                )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}

function formatRange(request: LeaveRequest): string {
    if (!request.start_date) {
        return '—';
    }

    const fmt = (date: string) =>
        new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });

    if (!request.end_date || request.start_date === request.end_date) {
        return fmt(request.start_date);
    }

    return `${fmt(request.start_date)} – ${fmt(request.end_date)}`;
}
