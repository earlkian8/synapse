import type { LedgerLine, NominationStatus, RedemptionStatus } from './types';

export const NOMINATION_STATUS: Record<
    NominationStatus,
    { label: string; className: string }
> = {
    pending: {
        label: 'Waiting for review',
        className:
            'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    },
    approved: {
        label: 'Approved',
        className:
            'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    rejected: {
        label: 'Not approved',
        className: 'border-border bg-muted text-muted-foreground',
    },
    withdrawn: {
        label: 'Withdrawn',
        className: 'border-border bg-muted text-muted-foreground',
    },
};

export const REDEMPTION_STATUS: Record<
    RedemptionStatus,
    { label: string; className: string }
> = {
    pending: {
        label: 'Requested',
        className:
            'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    },
    fulfilled: {
        label: 'Handed over',
        className:
            'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    declined: {
        label: 'Declined',
        className:
            'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-400',
    },
    cancelled: {
        label: 'Cancelled',
        className: 'border-border bg-muted text-muted-foreground',
    },
};

export const LEDGER_KIND: Record<LedgerLine['kind'], string> = {
    award: 'Award',
    kudos: 'Kudos',
    redemption: 'Reward',
    refund: 'Refund',
    adjustment: 'Adjustment',
};

/** "3 days ago", "just now" — relative, for the wall. */
export function ago(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

    if (seconds < 60) {
        return 'just now';
    }

    const steps: [number, Intl.RelativeTimeFormatUnit][] = [
        [60, 'minute'],
        [3600, 'hour'],
        [86400, 'day'],
        [604800, 'week'],
    ];

    for (let i = steps.length - 1; i >= 0; i--) {
        const [size, unit] = steps[i];

        if (seconds >= size) {
            return format.format(-Math.floor(seconds / size), unit);
        }
    }

    return '';
}

export function formatPoints(points: number): string {
    return `${points.toLocaleString()} ${Math.abs(points) === 1 ? 'point' : 'points'}`;
}
