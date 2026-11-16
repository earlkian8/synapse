import { Head, router, usePage } from '@inertiajs/react';
import { CalendarDays, CalendarPlus, Pencil, X } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    DataTable,
    EmptyTableRow,
    PageBody,
    PageHeader,
    TableCard,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { FileLeaveDialog } from '@/features/leave/components/file-leave-dialog';
import { LeaveNav } from '@/features/leave/components/leave-nav';
import { LeaveTypeChip } from '@/features/leave/components/leave-type-chip';
import { RequestStatusBadge } from '@/features/leave/components/request-status-badge';
import { leaveRoutes } from '@/features/leave/routes';
import type { LeaveRequest, LeaveTypeRef } from '@/features/leave/types';

type Balance = {
    leave_type_id: number;
    name: string;
    color: string;
    entitled: number;
    used: number;
    pending: number;
    remaining: number;
};

type Props = {
    employee: { id: number; full_name: string; employee_no: string } | null;
    year: number;
    balances: Balance[];
    requests: LeaveRequest[];
    types: LeaveTypeRef[];
};

const days = (n: number) => `${n} ${n === 1 ? 'day' : 'days'}`;

function dateRange(request: LeaveRequest): string {
    const format = (iso: string | null) =>
        iso
            ? new Date(`${iso.slice(0, 10)}T00:00:00Z`).toLocaleDateString(
                  'en-US',
                  {
                      month: 'short',
                      day: 'numeric',
                      year: 'numeric',
                      timeZone: 'UTC',
                  },
              )
            : '—';

    return request.start_date === request.end_date
        ? `${format(request.start_date)}${request.is_half_day ? `, ${request.half_day_period === 'afternoon' ? 'afternoon' : 'morning'}` : ''}`
        : `${format(request.start_date)} – ${format(request.end_date)}`;
}

/**
 * My leave: how much of each kind is left this year, every request filed and
 * where it stands, and filing or cancelling one — what anybody who may file
 * their own leave needs, on the web as in the app.
 */
export default function MyLeave() {
    const { employee, year, balances, requests, types } =
        usePage<Props>().props;
    const [filing, setFiling] = useState<{
        open: boolean;
        request: LeaveRequest | null;
    }>({ open: false, request: null });
    const [cancelling, setCancelling] = useState<LeaveRequest | null>(null);
    const [processing, setProcessing] = useState(false);

    return (
        <>
            <Head title="My leave" />

            <PageBody>
                <PageHeader
                    title="My leave"
                    description="What you have left this year, and every request you’ve filed."
                    actions={<LeaveNav active="me" />}
                />

                {employee === null ? (
                    <p className="rounded-xl border bg-card px-5 py-8 text-center text-sm text-muted-foreground">
                        Your account isn’t linked to an employee record. Ask HR
                        to link it.
                    </p>
                ) : (
                    <>
                        <section
                            aria-label={`Your balances for ${year}`}
                            className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-4"
                        >
                            {balances.map((balance) => {
                                const share =
                                    balance.entitled > 0
                                        ? Math.min(
                                              1,
                                              (balance.used + balance.pending) /
                                                  balance.entitled,
                                          )
                                        : 0;

                                return (
                                    <div
                                        key={balance.leave_type_id}
                                        className="space-y-2 rounded-xl border bg-card p-4"
                                    >
                                        <p className="flex items-center gap-2 text-sm font-medium">
                                            <span
                                                className="size-2 shrink-0 rounded-full"
                                                style={{
                                                    backgroundColor:
                                                        balance.color,
                                                }}
                                            />
                                            {balance.name}
                                        </p>
                                        <p className="text-2xl font-semibold tracking-tight tabular-nums">
                                            {balance.remaining}
                                            <span className="ml-1 text-sm font-normal text-muted-foreground">
                                                of {balance.entitled} left
                                            </span>
                                        </p>
                                        <div
                                            className="h-1.5 overflow-hidden rounded-full bg-muted"
                                            aria-hidden
                                        >
                                            <div
                                                className="h-full rounded-full"
                                                style={{
                                                    width: `${share * 100}%`,
                                                    backgroundColor:
                                                        balance.color,
                                                }}
                                            />
                                        </div>
                                        {balance.pending > 0 && (
                                            <p className="text-xs text-muted-foreground">
                                                {days(balance.pending)} waiting
                                                for approval
                                            </p>
                                        )}
                                    </div>
                                );
                            })}
                        </section>

                        <TableCard
                            title="Your requests"
                            count={requests.length}
                            actions={
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        setFiling({ open: true, request: null })
                                    }
                                >
                                    <CalendarPlus className="size-4" />
                                    File leave
                                </Button>
                            }
                        >
                            <DataTable>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Leave</TableHead>
                                        <TableHead>When</TableHead>
                                        <TableHead className="text-right">
                                            Days
                                        </TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-px">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {requests.length === 0 && (
                                        <EmptyTableRow
                                            colSpan={5}
                                            icon={CalendarDays}
                                            title="No requests yet"
                                            description="File one when you need time off. Whoever approves leave is told at once."
                                        />
                                    )}
                                    {requests.map((request) => (
                                        <TableRow key={request.id}>
                                            <TableCell>
                                                {request.type && (
                                                    <LeaveTypeChip
                                                        name={request.type.name}
                                                        color={
                                                            request.type.color
                                                        }
                                                    />
                                                )}
                                                {request.reason && (
                                                    <p className="mt-1 line-clamp-1 max-w-64 text-xs text-muted-foreground">
                                                        {request.reason}
                                                    </p>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-sm whitespace-nowrap">
                                                {dateRange(request)}
                                            </TableCell>
                                            <TableCell className="text-right text-sm tabular-nums">
                                                {request.days}
                                            </TableCell>
                                            <TableCell>
                                                <RequestStatusBadge
                                                    status={request.status}
                                                />
                                                {request.review_note && (
                                                    <p className="mt-1 line-clamp-2 max-w-56 text-xs text-muted-foreground">
                                                        {request.review_note}
                                                    </p>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex justify-end gap-1">
                                                    {request.status ===
                                                        'pending' && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setFiling({
                                                                    open: true,
                                                                    request,
                                                                })
                                                            }
                                                            aria-label="Change this request"
                                                        >
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                    )}
                                                    {(request.status ===
                                                        'pending' ||
                                                        request.status ===
                                                            'approved') && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setCancelling(
                                                                    request,
                                                                )
                                                            }
                                                        >
                                                            <X className="size-4" />
                                                            Cancel
                                                        </Button>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </DataTable>
                        </TableCard>
                    </>
                )}
            </PageBody>

            {employee && (
                <FileLeaveDialog
                    request={filing.request}
                    employees={[employee]}
                    types={types}
                    open={filing.open}
                    onOpenChange={(open) =>
                        setFiling((prev) => ({ ...prev, open }))
                    }
                />
            )}

            <ConfirmDialog
                open={cancelling !== null}
                onOpenChange={(open) => !open && setCancelling(null)}
                title="Cancel this leave request?"
                description={
                    cancelling?.status === 'approved'
                        ? 'It was approved. Cancelling gives the days back to your balance.'
                        : 'It won’t be reviewed. You can file a new one later.'
                }
                confirmLabel="Cancel request"
                destructive
                processing={processing}
                onConfirm={() =>
                    cancelling &&
                    router.patch(
                        leaveRoutes.cancel(cancelling.hashid),
                        {},
                        {
                            preserveScroll: true,
                            onStart: () => setProcessing(true),
                            onFinish: () => {
                                setProcessing(false);
                                setCancelling(null);
                            },
                        },
                    )
                }
            />
        </>
    );
}

MyLeave.layout = {
    breadcrumbs: [
        { title: 'Leave', href: leaveRoutes.index },
        { title: 'My leave', href: leaveRoutes.me },
    ],
};
