/**
 * Moments arrive from the API as ISO 8601 and are shown in the admin's own
 * time zone.
 */
const dateTime = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const dateOnly = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });

const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

const STEPS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 365 * 24 * 3600],
    ['month', 30 * 24 * 3600],
    ['week', 7 * 24 * 3600],
    ['day', 24 * 3600],
    ['hour', 3600],
    ['minute', 60],
];

export function formatDateTime(iso: string | null | undefined): string {
    return iso ? dateTime.format(new Date(iso)) : '—';
}

export function formatDate(iso: string | null | undefined): string {
    return iso ? dateOnly.format(new Date(iso)) : '—';
}

/** "3 minutes ago", "yesterday", "just now". */
export function formatRelative(
    iso: string | null | undefined,
    empty = '—',
): string {
    if (!iso) {
        return empty;
    }

    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;

    for (const [unit, size] of STEPS) {
        if (Math.abs(seconds) >= size) {
            return relative.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}

export function formatNumber(value: number): string {
    return value.toLocaleString();
}

/** "m:ss" for a countdown. */
export function formatCountdown(milliseconds: number): string {
    const total = Math.max(0, Math.ceil(milliseconds / 1000));

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}
