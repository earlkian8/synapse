import { Link } from '@inertiajs/react';
import {
    ArchiveRestore,
    CalendarClock,
    Eye,
    Trash2,
    Users,
    Video,
} from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    RowMenuTrigger,
    rowOpens,
    SortableHead,
    TableCard,
} from '@/components/data-table';
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
import { cn } from '@/lib/utils';
import { formatDateTimeRange, TYPE_LABELS } from '../constants';
import { eventRoutes } from '../routes';
import type { EventItem } from '../types';
import { EventStatusBadge } from './event-status-badge';

export type EventSort =
    'title' | 'schedule' | 'location' | 'attendance' | 'status';

type Props = {
    events: EventItem[];
    sort: EventSort;
    direction: 'asc' | 'desc';
    onSort: (key: EventSort) => void;
    canManage: boolean;
    /** The list is the archive: rows restore or delete rather than open. */
    archived: boolean;
    filtered: boolean;
    onRestore: (event: EventItem) => void;
    onForceDelete: (event: EventItem) => void;
};

/**
 * Events and meetings: what, when, where, who is coming, and where each stands.
 * A row opens the event; archived ones are restored or deleted from their menu.
 */
export function EventTable({
    events,
    sort,
    direction,
    onSort,
    canManage,
    archived,
    filtered,
    onRestore,
    onForceDelete,
}: Props) {
    const sortable = (key: EventSort) => ({
        active: sort === key,
        direction,
        onSort: () => onSort(key),
    });

    return (
        <TableCard>
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <SortableHead {...sortable('title')}>
                            Event
                        </SortableHead>
                        <SortableHead {...sortable('schedule')}>
                            Schedule
                        </SortableHead>
                        <SortableHead {...sortable('location')}>
                            Location
                        </SortableHead>
                        <SortableHead {...sortable('attendance')} align="right">
                            Attending
                        </SortableHead>
                        <SortableHead {...sortable('status')}>
                            Status
                        </SortableHead>
                        <TableHead className="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {events.length === 0 && (
                        <EmptyTableRow
                            colSpan={6}
                            icon={CalendarClock}
                            title={
                                archived
                                    ? 'Nothing archived'
                                    : filtered
                                      ? 'No events match'
                                      : 'No events scheduled yet'
                            }
                            description={
                                archived
                                    ? 'Archived events and meetings appear here.'
                                    : filtered
                                      ? 'Try another type or status, or clear the search.'
                                      : canManage
                                        ? 'Schedule one with "New event", then invite attendees to it.'
                                        : 'No events or meetings have been scheduled yet.'
                            }
                        />
                    )}

                    {events.map((event) => {
                        const Icon =
                            event.type === 'meeting' ? Video : CalendarClock;
                        const href = eventRoutes.show(event.hashid);
                        const opens = rowOpens(href);

                        return (
                            <TableRow
                                key={event.id}
                                {...(archived ? {} : opens)}
                                className={cn(
                                    !archived && opens.className,
                                    archived && 'text-muted-foreground',
                                )}
                            >
                                <TableCell>
                                    <div className="flex min-w-0 items-center gap-2.5">
                                        <span
                                            className={cn(
                                                'flex size-8 shrink-0 items-center justify-center rounded-lg',
                                                archived
                                                    ? 'bg-muted text-muted-foreground'
                                                    : 'bg-[#0ABFBF]/10 text-[#0ABFBF]',
                                            )}
                                        >
                                            <Icon className="size-4" />
                                        </span>
                                        <div className="min-w-0">
                                            {archived ? (
                                                <p className="max-w-64 truncate text-sm font-medium">
                                                    {event.title}
                                                </p>
                                            ) : (
                                                <Link
                                                    href={href}
                                                    className="block max-w-64 truncate text-sm font-medium hover:text-[#0ABFBF]"
                                                >
                                                    {event.title}
                                                </Link>
                                            )}
                                            <p className="max-w-64 truncate text-xs text-muted-foreground">
                                                {TYPE_LABELS[event.type]}
                                                {event.organizer
                                                    ? ` · ${event.organizer.name}`
                                                    : ''}
                                            </p>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {formatDateTimeRange(
                                        event.starts_at,
                                        event.ends_at,
                                    )}
                                </TableCell>
                                <TableCell className="max-w-56 truncate text-sm text-muted-foreground">
                                    {event.location ?? '—'}
                                </TableCell>
                                <TableCell className="text-right text-sm tabular-nums">
                                    <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                        <Users className="size-3.5" />
                                        {event.attending_count}/
                                        {event.attendees_count}
                                    </span>
                                </TableCell>
                                <TableCell>
                                    {archived ? (
                                        <span className="inline-flex items-center rounded-full border border-border bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                            Archived
                                        </span>
                                    ) : (
                                        <EventStatusBadge
                                            status={event.status}
                                        />
                                    )}
                                </TableCell>
                                <TableCell className="text-right">
                                    {(!archived || canManage) && (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <RowMenuTrigger
                                                    label={`Actions for ${event.title}`}
                                                />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent
                                                align="end"
                                                className="w-48"
                                            >
                                                {archived ? (
                                                    <>
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                onRestore(event)
                                                            }
                                                        >
                                                            <ArchiveRestore className="size-4" />
                                                            Restore
                                                        </DropdownMenuItem>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            onSelect={() =>
                                                                onForceDelete(
                                                                    event,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="size-4" />
                                                            Delete permanently
                                                        </DropdownMenuItem>
                                                    </>
                                                ) : (
                                                    <DropdownMenuItem asChild>
                                                        <Link href={href}>
                                                            <Eye className="size-4" />
                                                            Open event
                                                        </Link>
                                                    </DropdownMenuItem>
                                                )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    )}
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}
