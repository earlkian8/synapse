import type { ExportFormat, ExportStatus } from './types';

/** How often the screen checks on an export being prepared. */
export const POLL_INTERVAL_MS = 3000;

/** The order the sidebar shows its sections in. */
export const SECTION_ORDER = [
    'Talent Acquisition',
    'Workforce',
    'Offboarding',
    'Analytics & AI',
    'Company Setup',
    'System',
] as const;

export const FORMATS: {
    value: ExportFormat;
    label: string;
    hint: string;
}[] = [
    {
        value: 'csv',
        label: 'CSV',
        hint: 'Opens in Excel, Numbers or Google Sheets.',
    },
    {
        value: 'json',
        label: 'JSON',
        hint: 'For moving your records into another system.',
    },
];

export const STATUS_META: Record<
    ExportStatus,
    { label: string; className: string }
> = {
    queued: {
        label: 'Queued',
        className:
            'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-400',
    },
    building: {
        label: 'Preparing',
        className:
            'border-[#0ABFBF]/30 bg-[#0ABFBF]/10 text-[#078f8f] dark:text-[#0ABFBF]',
    },
    ready: {
        label: 'Ready',
        className:
            'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    failed: {
        label: 'Failed',
        className:
            'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-400',
    },
    expired: {
        label: 'Expired',
        className: 'border-border bg-muted text-muted-foreground',
    },
};

export const isInProgress = (status: ExportStatus): boolean =>
    status === 'queued' || status === 'building';

const numberFormat = new Intl.NumberFormat();

export function formatCount(value: number): string {
    return numberFormat.format(value);
}

/** 1536 → "1.5 KB". */
export function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`;
}

/** "Oct 5, 2026, 3:04 PM" in the viewer's own clock. */
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

/** "in 6 days", "in 3 hours" — how long an archive is still kept. */
export function formatUntil(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);

    if (seconds <= 0) {
        return 'now';
    }

    const hours = Math.round(seconds / 3600);

    if (hours < 1) {
        return 'within the hour';
    }

    if (hours < 48) {
        return `in ${hours} ${hours === 1 ? 'hour' : 'hours'}`;
    }

    const days = Math.round(hours / 24);

    return `in ${days} days`;
}
