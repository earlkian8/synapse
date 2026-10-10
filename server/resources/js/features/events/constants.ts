import type {
    AttendeeResponse,
    EventStatus,
    EventType,
    InviteeAnswer,
    RepeatFrequency,
} from './types';

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

/** How long before the start an automatic reminder can go out (ADR 0070). */
export const REMINDER_OPTIONS: { value: number; label: string }[] = [
    { value: 10, label: '10 minutes before' },
    { value: 30, label: '30 minutes before' },
    { value: 60, label: '1 hour before' },
    { value: 120, label: '2 hours before' },
    { value: 1440, label: '1 day before' },
    { value: 2880, label: '2 days before' },
];

/** "1 hour before", or null when no reminder is set. */
export function reminderLabel(minutes: number | null): string | null {
    return (
        REMINDER_OPTIONS.find((option) => option.value === minutes)?.label ??
        null
    );
}

/** An invitee's own answers, as they read to them. */
export const ANSWER_LABELS: Record<InviteeAnswer, string> = {
    accepted: 'Going',
    tentative: 'Maybe',
    declined: 'Not going',
};

export const ANSWER_ORDER: InviteeAnswer[] = [
    'accepted',
    'tentative',
    'declined',
];

/** The chosen answer's own colour, filled; the others stay quiet. */
export const ANSWER_ACTIVE_STYLES: Record<InviteeAnswer, string> = {
    accepted:
        'border-emerald-600 bg-emerald-600 text-white hover:bg-emerald-600/90 dark:border-emerald-500 dark:bg-emerald-500 dark:text-emerald-950',
    tentative:
        'border-amber-500 bg-amber-500 text-amber-950 hover:bg-amber-500/90',
    declined:
        'border-rose-600 bg-rose-600 text-white hover:bg-rose-600/90 dark:border-rose-500 dark:bg-rose-500 dark:text-rose-950',
};

export const FREQUENCY_LABELS: Record<RepeatFrequency, string> = {
    daily: 'Daily',
    weekly: 'Weekly',
    monthly: 'Monthly',
};

export const FREQUENCY_UNITS: Record<RepeatFrequency, [string, string]> = {
    daily: ['day', 'days'],
    weekly: ['week', 'weeks'],
    monthly: ['month', 'months'],
};

/** ISO weekdays, Monday first. */
export const WEEKDAYS: { value: number; short: string; long: string }[] = [
    { value: 1, short: 'M', long: 'Monday' },
    { value: 2, short: 'T', long: 'Tuesday' },
    { value: 3, short: 'W', long: 'Wednesday' },
    { value: 4, short: 'T', long: 'Thursday' },
    { value: 5, short: 'F', long: 'Friday' },
    { value: 6, short: 'S', long: 'Saturday' },
    { value: 7, short: 'S', long: 'Sunday' },
];

/** The ISO weekday (1 = Monday) of a `datetime-local` value. */
export function isoWeekday(local: string): number | null {
    if (!local) {
        return null;
    }

    const day = new Date(local).getDay();

    return day === 0 ? 7 : day;
}

/** Read Laravel's XSRF cookie so a plain fetch passes CSRF verification. */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** A JSON request to the app, with the session and CSRF token. */
export async function fetchJson<T>(
    url: string,
    init: RequestInit = {},
): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
            ...init.headers,
        },
    });

    if (!response.ok) {
        throw new Error(`Request failed (${response.status})`);
    }

    return (await response.json()) as T;
}
