import type { ForecastBand } from './types';

export const BAND_LABELS: Record<ForecastBand, string> = {
    exceeds: 'Exceeds',
    on_track: 'On track',
    below: 'Below target',
};

/** What each band means, for tooltips / legends. */
export const BAND_DESCRIPTIONS: Record<ForecastBand, string> = {
    exceeds: 'Projected to exceed expectations',
    on_track: 'Projected to meet expectations',
    below: 'Projected below target — may need support',
};

export const BAND_STYLES: Record<ForecastBand, string> = {
    exceeds:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    on_track: 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400',
    below: 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
};

/** Bands in display order (best first). */
export const BAND_ORDER: ForecastBand[] = ['exceeds', 'on_track', 'below'];

/** Colour for a predicted rating, matching the band bands (60 / 80). */
export function ratingTone(rating: number): string {
    if (rating >= 80) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (rating >= 60) {
        return 'text-sky-600 dark:text-sky-400';
    }

    return 'text-amber-600 dark:text-amber-400';
}

/** Bar / stroke fill colour for a predicted rating. */
export function ratingBarTone(rating: number): string {
    if (rating >= 80) {
        return 'bg-emerald-500';
    }

    if (rating >= 60) {
        return 'bg-sky-500';
    }

    return 'bg-amber-500';
}

/** A hex stroke matching {@see ratingBarTone}, for the SVG trajectory. */
export function ratingStroke(rating: number): string {
    if (rating >= 80) {
        return '#10b981';
    }

    if (rating >= 60) {
        return '#0ea5e9';
    }

    return '#f59e0b';
}

/** Format a predicted rating as a whole number, e.g. "74". */
export function formatRating(rating: number | null): string {
    return rating === null ? '—' : Math.round(rating).toString();
}

/** Format a 0–1 confidence as a percentage, e.g. "80%". */
export function formatConfidence(confidence: number | null): string {
    return confidence === null ? '—' : `${Math.round(confidence * 100)}%`;
}

/** Bucket a 0–1 confidence into a friendly label. */
export function confidenceLabel(confidence: number): string {
    if (confidence >= 0.66) {
        return 'High confidence';
    }

    if (confidence >= 0.33) {
        return 'Moderate confidence';
    }

    return 'Low confidence';
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
