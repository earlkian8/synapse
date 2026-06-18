import type { EvaluationStatus, PeriodStatus } from './types';

/** The inclusive bounds of the rating scale, mirroring PerformanceScorer. */
export const RATING_MIN = 1;

export const RATING_MAX = 5;

/** The whole-number rating options offered by the scorecard. */
export const RATING_VALUES = [1, 2, 3, 4, 5] as const;

/** What each rating on the 1–5 scale means. */
export const RATING_LABELS: Record<number, string> = {
    1: 'Needs Improvement',
    2: 'Below Expectations',
    3: 'Meets Expectations',
    4: 'Exceeds Expectations',
    5: 'Outstanding',
};

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

/** Weighted average of scored lines on the 1–5 scale (client mirror of the server). */
export function computeOverall(
    lines: { score: number | null; weight: number }[],
): number | null {
    const scored = lines.filter((line) => line.score !== null);

    if (scored.length === 0) {
        return null;
    }

    const totalWeight = scored.reduce(
        (sum, line) => sum + (line.weight || 0),
        0,
    );

    if (totalWeight <= 0) {
        return (
            Math.round(
                (scored.reduce((sum, line) => sum + (line.score ?? 0), 0) /
                    scored.length) *
                    100,
            ) / 100
        );
    }

    const weighted = scored.reduce(
        (sum, line) => sum + (line.score ?? 0) * (line.weight || 0),
        0,
    );

    return Math.round((weighted / totalWeight) * 100) / 100;
}
