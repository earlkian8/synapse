import type {
    EvaluationStatus,
    PeriodStatus,
    ScaleLevel,
    ScaleType,
} from './types';

/** The inclusive bounds of the canonical overall scale, mirroring PerformanceScorer. */
export const RATING_MIN = 1;

export const RATING_MAX = 5;

/** The whole-number rating options offered by a legacy 1–5 scorecard. */
export const RATING_VALUES = [1, 2, 3, 4, 5] as const;

/** What each rating on the canonical 1–5 scale means (used for the overall). */
export const RATING_LABELS: Record<number, string> = {
    1: 'Needs Improvement',
    2: 'Below Expectations',
    3: 'Meets Expectations',
    4: 'Exceeds Expectations',
    5: 'Outstanding',
};

/** The minimal shape a scale helper needs from a score line. */
export type ScaledLine = {
    score: number | null;
    scale_type: ScaleType;
    scale_min: number;
    scale_max: number;
    scale_levels: ScaleLevel[] | null;
};

/** A short human descriptor of a line's rating scale, e.g. "1–5", "0–100%". */
export function scaleDescriptor(line: {
    scale_type: ScaleType;
    scale_min: number;
    scale_max: number;
    scale_levels: ScaleLevel[] | null;
}): string {
    if (line.scale_type === 'percentage') {
        return '0–100%';
    }

    if (line.scale_type === 'scale') {
        const n = line.scale_levels?.length ?? 0;

        return `${n} levels`;
    }

    return `${line.scale_min}–${line.scale_max}`;
}

/** Render a raw line score in its own scale, e.g. "4", "82%", "Proficient". */
export function formatLineScore(line: ScaledLine): string {
    if (line.score === null) {
        return '—';
    }

    if (line.scale_type === 'percentage') {
        return `${line.score}%`;
    }

    if (line.scale_type === 'scale') {
        const level = line.scale_levels?.find((l) => l.value === line.score);

        return level ? level.label : String(line.score);
    }

    return String(line.score);
}

/** A line's raw score as a 0–1 fraction of its own scale (null when unscored). */
export function scaleFraction(line: {
    score: number | null;
    scale_min: number;
    scale_max: number;
}): number | null {
    if (line.score === null) {
        return null;
    }

    const span = line.scale_max - line.scale_min;

    if (span <= 0) {
        return 0;
    }

    return Math.max(0, Math.min(1, (line.score - line.scale_min) / span));
}

export const EVALUATION_STATUS_LABELS: Record<EvaluationStatus, string> = {
    draft: 'Draft',
    submitted: 'Submitted',
    acknowledged: 'Acknowledged',
};

export const EVALUATION_STATUS_STYLES: Record<EvaluationStatus, string> = {
    draft: 'border-slate-500/25 bg-slate-500/10 text-slate-600 dark:text-slate-300',
    submitted:
        'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    acknowledged:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
};

export const PERIOD_STATUS_LABELS: Record<PeriodStatus, string> = {
    draft: 'Draft',
    open: 'Open',
    closed: 'Closed',
};

export const PERIOD_STATUS_STYLES: Record<PeriodStatus, string> = {
    draft: 'border-slate-500/25 bg-slate-500/10 text-slate-600 dark:text-slate-300',
    open: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    closed: 'border-border bg-muted text-muted-foreground',
};

/** Format an overall / line score on the 1–5 scale, e.g. "4.08". */
export function formatScore(score: number | null): string {
    return score === null ? '—' : score.toFixed(2);
}

/** The nearest rating label for an overall (weighted) score. */
export function ratingLabelForScore(score: number | null): string | null {
    if (score === null) {
        return null;
    }

    return (
        RATING_LABELS[
            Math.max(RATING_MIN, Math.min(RATING_MAX, Math.round(score)))
        ] ?? null
    );
}

/** Colour band for a score, from rose (low) to emerald (high). */
export function scoreTone(score: number | null): string {
    if (score === null) {
        return 'text-muted-foreground';
    }

    if (score >= 4) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (score >= 3) {
        return 'text-[#0a8b91] dark:text-[#0ABFBF]';
    }

    if (score >= 2) {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-rose-600 dark:text-rose-400';
}

/** The fill colour for a score progress bar, matching {@see scoreTone}. */
export function scoreBarTone(score: number | null): string {
    if (score === null) {
        return 'bg-muted-foreground/40';
    }

    if (score >= 4) {
        return 'bg-emerald-500';
    }

    if (score >= 3) {
        return 'bg-[#0ABFBF]';
    }

    if (score >= 2) {
        return 'bg-amber-500';
    }

    return 'bg-rose-500';
}

/** Format an ISO date (YYYY-MM-DD) as "Jun 16, 2026". */
export function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

/**
 * Weighted overall on the canonical 1–5 scale (client mirror of PerformanceScorer):
 * each line is normalised to a 0–1 fraction of its own scale, weighted-averaged,
 * then projected back onto 1–5. Mixed scales combine coherently.
 */
export function computeOverall(
    lines: {
        score: number | null;
        weight: number;
        scale_min: number;
        scale_max: number;
    }[],
): number | null {
    const scored = lines.filter((line) => line.score !== null);

    if (scored.length === 0) {
        return null;
    }

    const fraction = (line: {
        score: number | null;
        scale_min: number;
        scale_max: number;
    }): number => scaleFraction(line) ?? 0;

    const totalWeight = scored.reduce(
        (sum, line) => sum + (line.weight || 0),
        0,
    );

    const avgFraction =
        totalWeight > 0
            ? scored.reduce(
                  (sum, line) => sum + fraction(line) * (line.weight || 0),
                  0,
              ) / totalWeight
            : scored.reduce((sum, line) => sum + fraction(line), 0) /
              scored.length;

    return Math.round((1 + avgFraction * 4) * 100) / 100;
}
