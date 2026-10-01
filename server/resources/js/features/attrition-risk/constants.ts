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

/** "1.5 yrs" / "8 mos" — tenure-style durations read better in months under a year. */
function formatYears(value: number | string): string {
    const years = Number(value);

    if (years < 1) {
        const months = Math.max(0, Math.round(years * 12));

        return `${months} ${months === 1 ? 'mo' : 'mos'}`;
    }

    return `${years.toFixed(1)} yrs`;
}

function plural(value: number | string, one: string, many: string): string {
    const n = Math.round(Number(value));

    return `${n} ${n === 1 ? one : many}`;
}

const EMPLOYMENT_TYPE_LABELS: Record<string, string> = {
    regular: 'Regular',
    probationary: 'Probationary',
    part_time: 'Part-time',
    contractual: 'Contractual',
};

/**
 * Every input the attrition model takes, with friendly labels and formatters —
 * the same eight questions the survey it learned from asked. Only those present
 * in an employee's snapshot are shown as recorded; the rest were imputed and are
 * listed as "not on record" rather than presented as fact.
 */
export const INPUT_FIELDS: {
    key: string;
    label: string;
    format: (value: number | string) => string;
}[] = [
    {
        key: 'employment_type',
        label: 'Employment type',
        format: (v) => EMPLOYMENT_TYPE_LABELS[String(v)] ?? String(v),
    },
    {
        key: 'tenure_years',
        label: 'Tenure',
        format: formatYears,
    },
    {
        key: 'monthly_salary',
        label: 'Monthly salary',
        format: (v) =>
            `₱${Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 })}`,
    },
    {
        key: 'years_since_promotion',
        label: 'Since last promotion',
        format: formatYears,
    },
    {
        key: 'ever_promoted',
        label: 'Promoted here before',
        format: (v) => (Number(v) > 0 ? 'Yes' : 'Never'),
    },
    {
        key: 'overtime_hours_90d',
        label: 'Overtime, last 90 days',
        format: (v) => `${Math.round(Number(v))} h`,
    },
    {
        key: 'absences_90d',
        label: 'Absences, last 90 days',
        format: (v) => plural(v, 'day', 'days'),
    },
    {
        key: 'lates_90d',
        label: 'Late arrivals, last 90 days',
        format: (v) => plural(v, 'time', 'times'),
    },
];
