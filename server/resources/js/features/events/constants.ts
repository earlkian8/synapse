import type { AttendeeResponse, EventStatus, EventType } from './types';

export const TYPE_LABELS: Record<EventType, string> = {
    event: 'Event',
    meeting: 'Meeting',
};

export const STATUS_LABELS: Record<EventStatus, string> = {
    upcoming: 'Upcoming',
    ongoing: 'Happening now',
    past: 'Past',
};

export const STATUS_STYLES: Record<EventStatus, string> = {
    upcoming: 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400',
    ongoing:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    past: 'border-border bg-muted text-muted-foreground',
};

/** Display order for the event sections on the overview. */
export const STATUS_ORDER: EventStatus[] = ['ongoing', 'upcoming', 'past'];

export const RESPONSE_LABELS: Record<AttendeeResponse, string> = {
    invited: 'Invited',
    accepted: 'Accepted',
    declined: 'Declined',
    tentative: 'Tentative',
};

export const RESPONSE_STYLES: Record<AttendeeResponse, string> = {
    invited:
        'border-slate-500/25 bg-slate-500/10 text-slate-600 dark:text-slate-300',
    accepted:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    declined:
        'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
    tentative:
        'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
};

/** The responses an invitee can be set to, in a sensible order. */
export const RESPONSE_ORDER: AttendeeResponse[] = [
    'invited',
    'accepted',
    'tentative',
    'declined',
];

/** "Jun 16, 2026, 2:30 PM" */
export function formatDateTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

/** Convert an ISO datetime to a `datetime-local` input value (local time). */
export function toDateTimeLocal(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const d = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** "Jun 16, 2026" */
export function formatDay(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

/** "2:30 PM" */
export function formatTime(iso: string | null): string {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });
}

/**
 * A human window: "Jun 16, 2026, 2:30 – 4:00 PM" when same-day, "Jun 16 – 18,
 * 2026" across days, or just the start when there is no end.
 */
export function formatDateTimeRange(
    start: string | null,
    end: string | null,
): string {
    if (!start) {
        return '—';
    }

    if (!end) {
        return formatDateTime(start);
    }

    const startDate = new Date(start);
    const endDate = new Date(end);
    const sameDay = startDate.toDateString() === endDate.toDateString();

    if (sameDay) {
        return `${formatDay(start)}, ${formatTime(start)} – ${formatTime(end)}`;
    }

    return `${formatDateTime(start)} – ${formatDateTime(end)}`;
}
