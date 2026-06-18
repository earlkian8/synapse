import { Link } from '@inertiajs/react';
import { CalendarClock, MapPin, Users, Video } from 'lucide-react';
import { TYPE_LABELS, formatDateTimeRange } from '../constants';
import { eventRoutes } from '../routes';
import type { EventItem } from '../types';
import { EventStatusBadge } from './event-status-badge';

/** A tile on the events overview: kind, schedule, location and headcount. */
export function EventCard({ event }: { event: EventItem }) {
    const Icon = event.type === 'meeting' ? Video : CalendarClock;

    return (
        <Link
            href={eventRoutes.show(event.hashid)}
            className="group flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm transition-colors hover:border-[#0ABFBF]/50 dark:border-sidebar-border"
        >
            <div className="flex items-start justify-between gap-2">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                    <Icon className="size-4.5" />
                </span>
                <EventStatusBadge status={event.status} />
            </div>

            <div className="min-w-0">
                <p className="truncate text-sm font-semibold">{event.title}</p>
                <p className="truncate text-xs text-muted-foreground">
                    {TYPE_LABELS[event.type]} ·{' '}
                    {formatDateTimeRange(event.starts_at, event.ends_at)}
                </p>
            </div>

            <div className="mt-auto flex items-center justify-between gap-2 text-xs text-muted-foreground">
                <span className="flex min-w-0 items-center gap-1">
                    <MapPin className="size-3.5 shrink-0" />
                    <span className="truncate">
                        {event.location ?? 'No location'}
                    </span>
                </span>
                <span className="flex shrink-0 items-center gap-1 tabular-nums">
                    <Users className="size-3.5" />
                    {event.attending_count}/{event.attendees_count}
                </span>
            </div>
        </Link>
    );
}
