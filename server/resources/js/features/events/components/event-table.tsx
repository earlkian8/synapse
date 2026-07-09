import { router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    CalendarClock,
    ChevronsUpDown,
    Users,
    Video,
} from 'lucide-react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { TYPE_LABELS, formatDateTimeRange } from '../constants';
import { eventRoutes } from '../routes';
import type { EventItem } from '../types';
import { EventStatusBadge } from './event-status-badge';

export type EventSort = 'title' | 'schedule' | 'location' | 'attendance' | 'status';

type Props = {
    events: EventItem[];
    sort: EventSort;
    direction: 'asc' | 'desc';
    onSort: (key: EventSort) => void;
};

/** Events and meetings as a dense, sortable table — the default layout. */
export function EventTable({ events, sort, direction, onSort }: Props) {
    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader className="bg-muted/40">
                        <TableRow className="hover:bg-transparent">
                            <SortHead
                                label="Event"
                                sortKey="title"
                                sort={sort}
                                direction={direction}
                                onSort={onSort}
                            />
                            <SortHead
                                label="Schedule"
                                sortKey="schedule"
                                sort={sort}
                                direction={direction}
                                onSort={onSort}
                            />
                            <SortHead
                                label="Location"
                                sortKey="location"
                                sort={sort}
                                direction={direction}
                                onSort={onSort}
                            />
                            <SortHead
                                label="Attending"
                                sortKey="attendance"
                                sort={sort}
                                direction={direction}
                                onSort={onSort}
                                align="right"
                            />
                            <SortHead
                                label="Status"
                                sortKey="status"
                                sort={sort}
                                direction={direction}
                                onSort={onSort}
                            />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {events.map((event) => {
                            const Icon =
                                event.type === 'meeting' ? Video : CalendarClock;

                            return (
                                <TableRow
                                    key={event.id}
                                    onClick={() =>
                                        router.get(eventRoutes.show(event.hashid))
                                    }
                                    className="cursor-pointer"
                                >
                                    <TableCell>
                                        <div className="flex min-w-0 items-center gap-2.5">
                                            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                                <Icon className="size-4" />
                                            </span>
                                            <div className="flex min-w-0 flex-col">
                                                <span className="truncate text-sm font-medium">
                                                    {event.title}
                                                </span>
                                                <span className="truncate text-xs text-muted-foreground">
                                                    {TYPE_LABELS[event.type]}
                                                    {event.organizer
                                                        ? ` · ${event.organizer.name}`
                                                        : ''}
                                                </span>
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                                        {formatDateTimeRange(
                                            event.starts_at,
                                            event.ends_at,
                                        )}
                                    </TableCell>
                                    <TableCell className="max-w-[14rem] truncate text-sm text-muted-foreground">
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
                                        <EventStatusBadge status={event.status} />
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}

function SortHead({
    label,
    sortKey,
    sort,
    direction,
    onSort,
    align = 'left',
}: {
    label: string;
    sortKey: EventSort;
    sort: EventSort;
    direction: 'asc' | 'desc';
    onSort: (key: EventSort) => void;
    align?: 'left' | 'right';
}) {
    const active = sort === sortKey;

    return (
        <TableHead className={cn(align === 'right' && 'text-right')}>
            <button
                type="button"
                onClick={() => onSort(sortKey)}
                className={cn(
                    'inline-flex items-center gap-1 transition-colors hover:text-foreground',
                    align === 'right' && 'flex-row-reverse',
                    active && 'text-foreground',
                )}
            >
                {label}
                {active ? (
                    direction === 'asc' ? (
                        <ArrowUp className="size-3.5" />
                    ) : (
                        <ArrowDown className="size-3.5" />
                    )
                ) : (
                    <ChevronsUpDown className="size-3.5 opacity-50" />
                )}
            </button>
        </TableHead>
    );
}
