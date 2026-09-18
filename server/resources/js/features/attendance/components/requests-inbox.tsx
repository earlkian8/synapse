import { router } from '@inertiajs/react';
import { Check, FilePlus2, Inbox, Lock, Search, X } from 'lucide-react';
import { useState } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    describeRequest,
    formatDateRange,
    REQUEST_STATUS_FILTERS,
    REQUEST_TYPE_LABELS,
} from '../constants';
import { attendanceRoutes } from '../routes';
import type {
    AttendanceFilters,
    AttendanceRequestItem,
    AttendanceRequestType,
    DepartmentRef,
} from '../types';
import { RequestStatusBadge } from './request-status-badge';

type Props = {
    requests: AttendanceRequestItem[];
    filters: AttendanceFilters;
    departments: DepartmentRef[];
    /** HR may file on somebody's behalf. */
    canFile: boolean;
    onSearch: (value: string) => void;
    onDepartment: (value: number | null) => void;
    onStatus: (value: AttendanceFilters['request_status']) => void;
    onType: (value: AttendanceRequestType | null) => void;
    onOpen: (request: AttendanceRequestItem) => void;
    onFile: () => void;
};

type Bulk = { action: 'approve' | 'reject'; hashids: string[] };

/**
 * The request inbox (ADR 0039): what people asked attendance to know, oldest
 * waiting first, because that is the order to clear it in.
 *
 * Each row says who, which day, and what they asked for in words — "Time out
 * 6:00 PM", "2h overtime" — so most requests can be decided from the list. The
 * ones that cannot open in the review modal. Several can be decided at once
 * with one note; each is still decided on its own, so the reviewer's own
 * request, or one in a locked period, is left and counted.
 */
export function RequestsInbox({
    requests,
    filters,
    departments,
    canFile,
    onSearch,
    onDepartment,
    onStatus,
    onType,
    onOpen,
    onFile,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const [selected, setSelected] = useState<string[]>([]);
    const [bulk, setBulk] = useState<Bulk | null>(null);

    const decidable = requests.filter((request) => request.can.review);
    const chosen = selected.filter((hashid) =>
        decidable.some((request) => request.hashid === hashid),
    );
    const allChosen =
        decidable.length > 0 && chosen.length === decidable.length;

    const toggle = (hashid: string) =>
        setSelected((prev) =>
            prev.includes(hashid)
                ? prev.filter((value) => value !== hashid)
                : [...prev, hashid],
        );

    const quick = (
        request: AttendanceRequestItem,
        action: 'approve' | 'reject',
    ) =>
        router.patch(
            attendanceRoutes.requestReview(request.hashid),
            { action },
            { preserveScroll: true },
        );

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div
                    className="flex items-center gap-1 overflow-x-auto rounded-lg bg-muted/60 p-1"
                    role="tablist"
                    aria-label="Request status"
                >
                    {REQUEST_STATUS_FILTERS.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            role="tab"
                            aria-selected={
                                filters.request_status === option.value
                            }
                            onClick={() => {
                                setSelected([]);
                                onStatus(option.value);
                            }}
                            className={cn(
                                'rounded-md px-3 py-1 text-sm font-medium whitespace-nowrap transition-colors',
                                filters.request_status === option.value
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <form
                        className="relative"
                        onSubmit={(event) => {
                            event.preventDefault();
                            onSearch(search);
                        }}
                    >
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            onBlur={() =>
                                search !== filters.search && onSearch(search)
                            }
                            placeholder="Search people"
                            aria-label="Search people"
                            className="h-9 w-44 pl-8"
                        />
                    </form>
                    <Select
                        value={filters.request_type ?? 'all'}
                        onValueChange={(value) =>
                            onType(
                                value === 'all'
                                    ? null
                                    : (value as AttendanceRequestType),
                            )
                        }
                    >
                        <SelectTrigger
                            className="h-9 w-40"
                            aria-label="Request type"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Every kind</SelectItem>
                            {Object.entries(REQUEST_TYPE_LABELS).map(
                                ([value, label]) => (
                                    <SelectItem key={value} value={value}>
                                        {label}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                    <Select
                        value={
                            filters.department
                                ? String(filters.department)
                                : 'all'
                        }
                        onValueChange={(value) =>
                            onDepartment(value === 'all' ? null : Number(value))
                        }
                    >
                        <SelectTrigger
                            className="h-9 w-40"
                            aria-label="Department"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All departments</SelectItem>
                            {departments.map((department) => (
                                <SelectItem
                                    key={department.id}
                                    value={String(department.id)}
                                >
                                    {department.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {canFile && (
                        <Button size="sm" className="h-9" onClick={onFile}>
                            <FilePlus2 className="size-4" />
                            File for someone
                        </Button>
                    )}
                </div>
            </div>

            {chosen.length > 0 && (
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-[#0ABFBF]/30 bg-[#0ABFBF]/[0.06] px-3 py-2">
                    <p className="text-sm">
                        <span className="font-semibold tabular-nums">
                            {chosen.length}
                        </span>{' '}
                        selected
                    </p>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setSelected([])}
                        >
                            Clear
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            className="border-rose-500/30 text-rose-600 hover:bg-rose-500/10 hover:text-rose-600 dark:text-rose-400"
                            onClick={() =>
                                setBulk({ action: 'reject', hashids: chosen })
                            }
                        >
                            <X className="size-4" />
                            Reject
                        </Button>
                        <Button
                            size="sm"
                            className="bg-emerald-600 text-white hover:bg-emerald-600/90"
                            onClick={() =>
                                setBulk({ action: 'approve', hashids: chosen })
                            }
                        >
                            <Check className="size-4" />
                            Approve
                        </Button>
                    </div>
                </div>
            )}

            {requests.length === 0 ? (
                <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
                    <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                        <Inbox className="size-5" />
                    </span>
                    <p className="text-sm font-medium">
                        {filters.request_status === 'pending'
                            ? 'Nothing is waiting for a decision'
                            : 'No requests match this view'}
                    </p>
                    <p className="max-w-sm text-sm text-muted-foreground">
                        {filters.request_status === 'pending'
                            ? 'Corrections, overtime, official business and remote work that people ask for land here.'
                            : 'Try another status, kind or department.'}
                    </p>
                </div>
            ) : (
                <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                    {decidable.length > 0 && (
                        <div className="flex items-center gap-3 border-b border-border px-3 py-2 text-xs text-muted-foreground">
                            <Checkbox
                                checked={allChosen}
                                onCheckedChange={(checked) =>
                                    setSelected(
                                        checked
                                            ? decidable.map(
                                                  (request) => request.hashid,
                                              )
                                            : [],
                                    )
                                }
                                aria-label="Select every request you can decide"
                            />
                            <span>
                                {decidable.length} you can decide
                                {decidable.length < requests.length
                                    ? ` · ${requests.length - decidable.length} you cannot (your own, decided, or locked)`
                                    : ''}
                            </span>
                        </div>
                    )}
                    <ul className="divide-y divide-border">
                        {requests.map((request) => (
                            <Row
                                key={request.hashid}
                                request={request}
                                selected={chosen.includes(request.hashid)}
                                onToggle={() => toggle(request.hashid)}
                                onOpen={() => onOpen(request)}
                                onApprove={() => quick(request, 'approve')}
                                onReject={() => quick(request, 'reject')}
                            />
                        ))}
                    </ul>
                </div>
            )}

            <BulkDialog
                bulk={bulk}
                onClose={() => setBulk(null)}
                onDone={() => {
                    setBulk(null);
                    setSelected([]);
                }}
            />
        </div>
    );
}

function Row({
    request,
    selected,
    onToggle,
    onOpen,
    onApprove,
    onReject,
}: {
    request: AttendanceRequestItem;
    selected: boolean;
    onToggle: () => void;
    onOpen: () => void;
    onApprove: () => void;
    onReject: () => void;
}) {
    const employee = request.employee;

    return (
        <li
            className={cn(
                'flex items-center gap-3 px-3 py-3 transition-colors hover:bg-muted/50 sm:gap-4',
                selected && 'bg-[#0ABFBF]/[0.05]',
            )}
        >
            <span className="flex w-4 shrink-0 justify-center">
                {request.can.review && (
                    <Checkbox
                        checked={selected}
                        onCheckedChange={onToggle}
                        aria-label={`Select ${employee?.full_name ?? 'request'}`}
                    />
                )}
            </span>

            <button
                type="button"
                onClick={onOpen}
                className="flex min-w-0 flex-1 items-center gap-3 text-left focus-visible:outline-none"
            >
                <PersonAvatar
                    name={employee?.full_name ?? 'Employee'}
                    initials={employee?.initials ?? '?'}
                    photo={employee?.photo}
                    className="shrink-0"
                />
                <span className="min-w-0 flex-1">
                    <span className="flex items-center gap-2">
                        <span className="truncate text-sm font-medium">
                            {employee?.full_name ?? 'Employee'}
                        </span>
                        <span className="hidden rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground sm:inline">
                            {REQUEST_TYPE_LABELS[request.type]}
                        </span>
                        {request.locked_period && (
                            <Lock
                                className="size-3.5 shrink-0 text-muted-foreground"
                                aria-label={`${request.locked_period} is locked`}
                            />
                        )}
                    </span>
                    <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                        <span className="text-foreground/80">
                            {formatDateRange(
                                request.start_date,
                                request.end_date,
                            )}
                        </span>
                        {' · '}
                        {describeRequest(request.type, request.payload)}
                        {request.created_human
                            ? ` · asked ${request.created_human}`
                            : ''}
                    </span>
                </span>
            </button>

            <div className="flex shrink-0 items-center gap-2">
                {request.can.review ? (
                    <div className="hidden items-center gap-1.5 sm:flex">
                        <Button
                            size="sm"
                            variant="outline"
                            className="h-8 border-emerald-500/30 text-emerald-600 hover:bg-emerald-500/10 hover:text-emerald-600 dark:text-emerald-400"
                            onClick={onApprove}
                        >
                            <Check className="size-4" />
                            Approve
                        </Button>
                        <Button
                            size="icon"
                            variant="outline"
                            className="size-8 text-muted-foreground hover:border-rose-500/30 hover:bg-rose-500/10 hover:text-rose-600"
                            aria-label="Reject"
                            onClick={onReject}
                        >
                            <X className="size-4" />
                        </Button>
                    </div>
                ) : (
                    <RequestStatusBadge status={request.status} />
                )}
            </div>
        </li>
    );
}

/** One note for several decisions — each still decided on its own. */
function BulkDialog({
    bulk,
    onClose,
    onDone,
}: {
    bulk: Bulk | null;
    onClose: () => void;
    onDone: () => void;
}) {
    const [note, setNote] = useState('');
    const [processing, setProcessing] = useState(false);
    const approving = bulk?.action === 'approve';
    const count = bulk?.hashids.length ?? 0;

    const submit = () => {
        if (!bulk) {
            return;
        }

        router.patch(
            attendanceRoutes.requestBulkReview,
            {
                action: bulk.action,
                hashids: bulk.hashids,
                review_note: note || null,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setNote('');
                    onDone();
                },
            },
        );
    };

    return (
        <Dialog
            open={bulk !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {approving ? 'Approve' : 'Reject'} {count}{' '}
                        {count === 1 ? 'request' : 'requests'}?
                    </DialogTitle>
                    <DialogDescription>
                        {approving
                            ? 'Each day is changed as its request asks. Everyone is told.'
                            : 'The days stay as they are. Everyone is told.'}
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-1.5">
                    <Label htmlFor="bulk-note">
                        Note{' '}
                        <span className="font-normal text-muted-foreground">
                            (optional — each employee sees it)
                        </span>
                    </Label>
                    <textarea
                        id="bulk-note"
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                        rows={2}
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    />
                </div>
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={processing}
                        className={
                            approving
                                ? 'bg-emerald-600 text-white hover:bg-emerald-600/90'
                                : undefined
                        }
                        variant={approving ? 'default' : 'destructive'}
                    >
                        {processing && <Spinner />}
                        {approving ? 'Approve' : 'Reject'} {count}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
