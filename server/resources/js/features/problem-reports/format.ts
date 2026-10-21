/** Moments arrive as ISO 8601 and are shown on the reader's own clock. */
const dateOnly = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });
const dateTime = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

export function formatDate(iso: string | null): string {
    return iso ? dateOnly.format(new Date(iso)) : '—';
}

export function formatDateTime(iso: string | null): string {
    return iso ? dateTime.format(new Date(iso)) : '—';
}
