import type { ReadinessBasis, ReadinessTier } from './types';

/**
 * The reference workforce's promotion rate — what "average" means below. Mirrors
 * `base_rate` in the promotion model's contract
 * (model/artifacts/promotion/feature_contract.json); the tiers are multiples of it.
 */
export const REFERENCE_PROMOTION_RATE = 0.1;

export const TIER_LABELS: Record<ReadinessTier, string> = {
    high: 'High',
    medium: 'Medium',
    low: 'Low',
};

/** What each tier means: promotion odds against the reference average. */
export const TIER_DESCRIPTIONS: Record<ReadinessTier, string> = {
    high: 'At least twice as likely as average to be promoted',
    medium: 'At or above average odds of promotion',
    low: 'Below average odds of promotion',
};

export const TIER_STYLES: Record<ReadinessTier, string> = {
    high: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    medium: 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    low: 'border-slate-500/25 bg-slate-500/10 text-slate-600 dark:text-slate-300',
};

/** Tiers in display order (best first). */
export const TIER_ORDER: ReadinessTier[] = ['high', 'medium', 'low'];

/**
 * Text colour for a tier. Colour follows the tier, never a fixed score: the tiers
 * are cut on promotion odds, and the score is a percentile of those odds.
 */
export function tierTone(tier: ReadinessTier): string {
    return {
        high: 'text-emerald-600 dark:text-emerald-400',
        medium: 'text-amber-600 dark:text-amber-400',
        low: 'text-slate-500 dark:text-slate-400',
    }[tier];
}

/** Bar fill colour for a tier. */
export function tierBarTone(tier: ReadinessTier): string {
    return {
        high: 'bg-emerald-500',
        medium: 'bg-amber-500',
        low: 'bg-slate-400',
    }[tier];
}

export const BASIS_LABELS: Record<ReadinessBasis, string> = {
    two_appraisals: 'Two appraisals',
    latest_appraisal: 'One appraisal',
};

/** What a basis means for how far the score can be taken. */
export const BASIS_NOTES: Record<ReadinessBasis, string> = {
    two_appraisals:
        'Scored on the latest completed appraisal and how much it changed on the one before — the strongest signal of promotion there is.',
    latest_appraisal:
        'Only one completed appraisal is on record, so the change since the previous one — the strongest signal — is not known yet. This score averages over every change it could be; it will sharpen after the next cycle.',
};

/** Format a readiness score as a whole number, e.g. "96". */
export function formatScore(score: number | null): string {
    return score === null ? '—' : Math.round(score).toString();
}

/** A probability as a percentage, e.g. "12.4%" — never a false "0%". */
export function formatProbability(probability: number): string {
    if (probability < 0.001) {
        return 'under 0.1%';
    }

    return `${(probability * 100).toFixed(probability < 0.1 ? 1 : 0)}%`;
}

/** Promotion odds as a multiple of the reference average, e.g. "1.2×". */
export function formatLift(probability: number): string {
    const lift = probability / REFERENCE_PROMOTION_RATE;

    return lift < 0.1 ? 'under 0.1×' : `${lift.toFixed(1)}×`;
}

/** A signed change in attainment points, e.g. "+3.0 pts". */
export function formatChange(points: number): string {
    return `${points >= 0 ? '+' : '−'}${Math.abs(points).toFixed(1)} pts`;
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
