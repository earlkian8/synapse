import { ChevronDown, FilePlus2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    describeRequest,
    formatDateRange,
    REQUEST_TYPE_HINTS,
    REQUEST_TYPE_LABELS,
} from '../constants';
import type { AttendanceRequestItem, AttendanceRequestType } from '../types';
import { RequestStatusBadge } from './request-status-badge';

/**
 * The self-service "ask for something" menu (ADR 0039). A correction is asked
 * for from the day it concerns; the menu offers it too, for a day that is not
 * in the recent history.
 */
export function RequestMenu({
    onPick,
}: {
    onPick: (type: AttendanceRequestType) => void;
}) {
    const types: AttendanceRequestType[] = [
        'correction',
        'overtime',
        'official_business',
        'remote_work',
    ];

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm">
                    <FilePlus2 className="size-4" />
                    Request
                    <ChevronDown className="size-4 text-muted-foreground" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                    Ask attendance for…
                </DropdownMenuLabel>
                {types.map((type) => (
                    <DropdownMenuItem
                        key={type}
                        onSelect={() => onPick(type)}
                        className="flex-col items-start gap-0"
                    >
                        <span className="text-sm font-medium">
                            {REQUEST_TYPE_LABELS[type]}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {REQUEST_TYPE_HINTS[type]}
                        </span>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** The employee's own requests, newest first, each opening the same modal a reviewer uses. */
export function MyRequests({
    requests,
    onOpen,
}: {
    requests: AttendanceRequestItem[];
    onOpen: (request: AttendanceRequestItem) => void;
}) {
    return (
        <div className="rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <p className="border-b border-border px-5 py-3 text-xs font-medium text-muted-foreground">
                My requests
            </p>
            {requests.length === 0 ? (
                <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                    Nothing asked yet. Missed a punch? Use “Fix” on the day
                    below.
                </p>
            ) : (
                <ul className="divide-y divide-border">
                    {requests.map((request) => (
                        <li key={request.hashid}>
                            <button
                                type="button"
                                onClick={() => onOpen(request)}
                                className="flex w-full items-center gap-3 px-5 py-3 text-left transition-colors hover:bg-muted/50"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-medium">
                                        {REQUEST_TYPE_LABELS[request.type]}
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            ·{' '}
                                            {formatDateRange(
                                                request.start_date,
                                                request.end_date,
                                            )}
                                        </span>
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {request.review_note
                                            ? `“${request.review_note}”`
                                            : describeRequest(
                                                  request.type,
                                                  request.payload,
                                              )}
                                    </span>
                                </span>
                                <RequestStatusBadge status={request.status} />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
