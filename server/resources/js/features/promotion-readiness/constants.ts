import type { ReadinessTier } from './types';

export const TIER_LABELS: Record<ReadinessTier, string> = {
    high: 'High',
    medium: 'Medium',
    low: 'Low',
};

/** What each tier means, for tooltips / legends. */
export const TIER_DESCRIPTIONS: Record<ReadinessTier, string> = {
    high: 'Strong candidate for advancement',
    medium: 'Developing — watch and grow',
    low: 'Not yet ready for promotion',
};

export const TIER_STYLES: Record<ReadinessTier, string> = {
    high: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    medium: 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    low: 'border-slate-500/25 bg-slate-500/10 text-slate-600 dark:text-slate-300',
};

/** Tiers in display order (best first). */
export const TIER_ORDER: ReadinessTier[] = ['high', 'medium', 'low'];

/** Colour for a readiness score, matching the tier bands (0.33 / 0.66 → ×100). */
export function scoreTone(score: number): string {
    if (score >= 66) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (score >= 33) {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-slate-500 dark:text-slate-400';
}

/** Bar fill colour for a readiness score. */
export function scoreBarTone(score: number): string {
    if (score >= 66) {
        return 'bg-emerald-500';
    }

    if (score >= 33) {
        return 'bg-amber-500';
    }

    return 'bg-slate-400';
}

/** Format a readiness score as a whole number, e.g. "96". */
export function formatScore(score: number | null): string {
    return score === null ? '—' : Math.round(score).toString();
}

/** Format an ISO timestamp as a friendly absolute date-time. */
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

/** Compact relative time, e.g. "3h ago", "2d ago". */
export function formatRelative(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const then = new Date(iso).getTime();
    const seconds = Math.round((Date.now() - then) / 1000);

    if (seconds < 60) {
        return 'just now';
    }

    const minutes = Math.round(seconds / 60);

    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return `${hours}h ago`;
    }

    const days = Math.round(hours / 24);

    if (days < 30) {
        return `${days}d ago`;
    }

    return formatDateTime(iso);
}
