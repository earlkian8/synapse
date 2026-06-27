import type { RiskTier } from './types';

export const TIER_LABELS: Record<RiskTier, string> = {
    high: 'High risk',
    medium: 'At watch',
    low: 'Stable',
};

/** What each tier means, for tooltips / legends. */
export const TIER_DESCRIPTIONS: Record<RiskTier, string> = {
    high: 'Likely to leave — prioritise retention',
    medium: 'Some risk — worth a check-in',
    low: 'Settled — no immediate concern',
};

/**
 * Risk tier styles. Note the palette is INVERTED versus Promotion Readiness:
 * here a high score is a bad outcome, so high → rose, low → emerald.
 */
export const TIER_STYLES: Record<RiskTier, string> = {
    high: 'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
    medium: 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    low: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
};

/** Tiers in display order (most urgent first). */
export const TIER_ORDER: RiskTier[] = ['high', 'medium', 'low'];

/** Colour for a risk score, matching the tier bands (0.33 / 0.66 → ×100). */
export function scoreTone(score: number): string {
    if (score >= 66) {
        return 'text-rose-600 dark:text-rose-400';
    }

    if (score >= 33) {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-emerald-600 dark:text-emerald-400';
}

/** Bar fill colour for a risk score. */
export function scoreBarTone(score: number): string {
    if (score >= 66) {
        return 'bg-rose-500';
    }

    if (score >= 33) {
        return 'bg-amber-500';
    }

    return 'bg-emerald-500';
}

/** Format a risk score as a whole number, e.g. "47". */
export function formatScore(score: number | null): string {
    return score === null ? '—' : Math.round(score).toString();
}

/** Format a 0–1 confidence as a percentage, e.g. "86%". */
export function formatConfidence(confidence: number): string {
    return `${Math.round(confidence * 100)}%`;
}

/** A words label for a confidence level. */
export function confidenceLabel(confidence: number): string {
    if (confidence >= 0.75) {
        return 'High confidence';
    }

    if (confidence >= 0.5) {
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

/** Humanize the model version string "RandomForestClassifier@2026-…" → algorithm only. */
export function modelAlgorithm(version: string | null): string | null {
    if (!version) {
        return null;
    }

    return version.split('@')[0] ?? version;
}

/**
 * The grounded features we surface in the detail dialog, with friendly labels
 * and formatters. Only those present in the snapshot are shown — the rest were
 * imputed by the pipeline and are deliberately not presented as fact.
 */
export const INPUT_FIELDS: {
    key: string;
    label: string;
    format: (value: number | string) => string;
}[] = [
    {
        key: 'OverTime',
        label: 'Works overtime',
        format: (v) => String(v),
    },
    {
        key: 'MonthlyIncome',
        label: 'Monthly income',
        format: (v) => Number(v).toLocaleString(),
    },
    {
        key: 'YearsAtCompany',
        label: 'Tenure',
        format: (v) => `${Number(v).toFixed(1)} yrs`,
    },
    {
        key: 'YearsSinceLastPromotion',
        label: 'Since last promotion',
        format: (v) => `${Number(v).toFixed(1)} yrs`,
    },
    {
        key: 'YearsInCurrentRole',
        label: 'In current role',
        format: (v) => `${Number(v).toFixed(1)} yrs`,
    },
    {
        key: 'PerformanceRating',
        label: 'Latest rating',
        format: (v) => `${Math.round(Number(v))} / 4`,
    },
    {
        key: 'TrainingTimesLastYear',
        label: 'Training (last year)',
        format: (v) => `${Math.round(Number(v))}`,
    },
    {
        key: 'Department',
        label: 'Department',
        format: (v) => String(v),
    },
];
