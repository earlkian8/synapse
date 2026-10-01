import type { AttentionTone } from './types';

/** A cohesive accent set used across the dashboard's hand-drawn SVG charts. */
export const ACCENT = {
    teal: '#0ABFBF',
    indigo: '#6366F1',
    amber: '#F59E0B',
    slate: '#94A3B8',
} as const;

/** Attendance status colours — shared with the rest of the app's status palette. */
export const STATUS = {
    present: '#10B981',
    late: '#F59E0B',
    absent: '#F43F5E',
    on_leave: '#6366F1',
} as const;

/** Tailwind tints for the "needs attention" rows, keyed by tone. */
export const TONE: Record<
    AttentionTone,
    { dot: string; text: string; ring: string }
> = {
    amber: {
        dot: 'bg-amber-500',
        text: 'text-amber-600 dark:text-amber-400',
        ring: 'ring-amber-500/20',
    },
    rose: {
        dot: 'bg-rose-500',
        text: 'text-rose-600 dark:text-rose-400',
        ring: 'ring-rose-500/20',
    },
    teal: {
        dot: 'bg-[#0ABFBF]',
        text: 'text-[#0ABFBF]',
        ring: 'ring-[#0ABFBF]/20',
    },
};

/** Time-of-day greeting. */
export function greeting(date: Date): string {
    const hour = date.getHours();

    if (hour < 12) {
        return 'Good morning';
    }

    if (hour < 18) {
        return 'Good afternoon';
    }

    return 'Good evening';
}

/** "Sunday, June 28" — a warm, full date for the hero. */
export function longDate(date: Date): string {
    return date.toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
    });
}

/** Compact relative time: "just now", "5m ago", "3h ago", "2d ago", else a date. */
export function timeAgo(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const then = new Date(iso).getTime();
    const seconds = Math.round((Date.now() - then) / 1000);

    if (seconds < 45) {
        return 'just now';
    }

    if (seconds < 3600) {
        return `${Math.round(seconds / 60)}m ago`;
    }

    if (seconds < 86400) {
        return `${Math.round(seconds / 3600)}h ago`;
    }

    if (seconds < 604800) {
        return `${Math.round(seconds / 86400)}d ago`;
    }

    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}

/** Whole-number formatting with thousands separators. */
export function compact(value: number): string {
    return value.toLocaleString();
}
