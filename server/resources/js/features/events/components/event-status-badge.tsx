import { cn } from '@/lib/utils';
import {
    RESPONSE_LABELS,
    RESPONSE_STYLES,
    STATUS_LABELS,
    STATUS_STYLES,
} from '../constants';
import type { AttendeeResponse, EventStatus } from '../types';

const BASE =
    'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium';

/** A pill for an event's derived lifecycle status. */
export function EventStatusBadge({
    status,
    className,
}: {
    status: EventStatus;
    className?: string;
}) {
    return (
        <span className={cn(BASE, STATUS_STYLES[status], className)}>
            {STATUS_LABELS[status]}
        </span>
    );
}

/** A pill for an attendee's response. */
export function ResponseBadge({
    response,
    className,
}: {
    response: AttendeeResponse;
    className?: string;
}) {
    return (
        <span className={cn(BASE, RESPONSE_STYLES[response], className)}>
            {RESPONSE_LABELS[response]}
        </span>
    );
}
