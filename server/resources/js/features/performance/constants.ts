import type {
    BandTone,
    EvaluationStatus,
    GoalHealth,
    GoalStatus,
    PeriodStatus,
    ReviewRelationship,
    ReviewStatus,
    SessionStatus,
    RatingBand,
    ScaleLevel,
    ScaleType,
    ScoreResult,
    SectionResult,
} from './types';

/**
 * The client mirror of the server's scoring contract. Every number the scorecard
 * shows while HR is still typing is derived here, on exactly the rules
 * App\Support\Performance\PerformanceScorer applies when it saves — two-level
 * weighting, each line read on its own scale, attainment on 0–100.
 */

/** The bounds of the canonical (projected) overall, mirroring PerformanceScorer. */
export const RATING_MIN = 1;

export const RATING_MAX = 5;

/**
 * A band's tone rendered. One palette across the whole module: the ladder, the
 * chips, the distribution and the calibration table all read from here, so a
 * band means the same colour wherever it appears.
 */
export const BAND_TONES: Record<
    BandTone,
    { text: string; fill: string; soft: string; border: string }
> = {
    positive: {
        text: 'text-emerald-700 dark:text-emerald-300',
        fill: 'bg-emerald-500',
        soft: 'bg-emerald-500/10',
        border: 'border-emerald-500/30',
    },
    good: {
        text: 'text-[#0a7d82] dark:text-[#3fd6d6]',
        fill: 'bg-[#0ABFBF]',
        soft: 'bg-[#0ABFBF]/10',
        border: 'border-[#0ABFBF]/30',
    },
    neutral: {
        text: 'text-sky-700 dark:text-sky-300',
        fill: 'bg-sky-500',
        soft: 'bg-sky-500/10',
        border: 'border-sky-500/30',
    },
    caution: {
        text: 'text-amber-700 dark:text-amber-300',
        fill: 'bg-amber-500',
        soft: 'bg-amber-500/10',
        border: 'border-amber-500/30',
    },
    critical: {
        text: 'text-rose-700 dark:text-rose-300',
        fill: 'bg-rose-500',
        soft: 'bg-rose-500/10',
        border: 'border-rose-500/30',
    },
};

export function bandTone(tone: BandTone | undefined) {
    return BAND_TONES[tone ?? 'neutral'];
}

export const EVALUATION_STATUS_LABELS: Record<EvaluationStatus, string> = {
    draft: 'In progress',
    submitted: 'Awaiting sign-off',
    acknowledged: 'Signed off',
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

/** The minimal shape a scale helper needs from a score line. */
export type ScaledLine = {
    score: number | null;
    scale_type: ScaleType;
    scale_min: number;
    scale_max: number;
    scale_levels: ScaleLevel[] | null;
};

/** Trim a number to its shortest honest form: 4, 4.5, 82. */
export function trimNumber(value: number): string {
    return String(Math.round(value * 100) / 100);
}

/** Render a raw line score in its own scale — "4", "82%", "Proficient". */
export function formatLineScore(line: ScaledLine): string {
    if (line.score === null) {
        return '—';
    }

    if (line.scale_type === 'percentage') {
        return `${trimNumber(line.score)}%`;
    }

    if (line.scale_type === 'levels') {
        const level = line.scale_levels?.find((l) => l.value === line.score);

        return level ? level.label : trimNumber(line.score);
    }

    return trimNumber(line.score);
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

/** The values a scale can take, for the segmented rating control. */
export function scaleOptions(line: {
    scale_type: ScaleType;
    scale_min: number;
    scale_max: number;
    scale_step: number;
    scale_levels: ScaleLevel[] | null;
}): ScaleLevel[] | null {
    if (line.scale_type === 'levels') {
        return line.scale_levels ?? null;
    }

    if (line.scale_type === 'percentage') {
        return null;
    }

    const step = line.scale_step > 0 ? line.scale_step : 1;
    const count = Math.round((line.scale_max - line.scale_min) / step) + 1;

    // Above nine stops a segmented row stops being readable — the control falls
    // back to a number field instead.
    if (count > 9 || count < 2) {
        return null;
    }

    return Array.from({ length: count }, (_, i) => {
        const value = Math.round((line.scale_min + i * step) * 100) / 100;

        return { value, label: trimNumber(value), description: null };
    });
}

/** Format attainment on 0–100 — "72.5%", or an em dash when unscored. */
export function formatPercent(percent: number | null, digits = 1): string {
    return percent === null ? '—' : `${percent.toFixed(digits)}%`;
}

/** Format the 1–5 projection, e.g. "3.61". */
export function formatScore(score: number | null): string {
    return score === null ? '—' : score.toFixed(2);
}

/** The band a 0–100 attainment falls in — the highest cut it reaches. */
export function bandFor(
    percent: number | null,
    bands: RatingBand[],
): RatingBand | null {
    if (percent === null) {
        return null;
    }

    return (
        [...bands]
            .sort((a, b) => b.min_percent - a.min_percent)
            .find((band) => percent >= band.min_percent) ?? null
    );
}

/** The rating model in reading order — the highest cut first. */
export function orderedBands(bands: RatingBand[]): RatingBand[] {
    return [...bands].sort((a, b) => b.min_percent - a.min_percent);
}

/** The span a band occupies on the 0–100 ladder. */
export function bandSpan(
    band: RatingBand,
    bands: RatingBand[],
): { from: number; to: number } {
    const above = orderedBands(bands)
        .filter((b) => b.min_percent > band.min_percent)
        .map((b) => b.min_percent);

    return {
        from: band.min_percent,
        to: above.length > 0 ? Math.min(...above) : 100,
    };
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

/** The line fields the client-side scorer reads. */
type ScorableLine = ScaledLine & {
    weight: number;
    section_key: string;
    section_name: string | null;
    section_weight: number;
};

/**
 * The live result for a scorecard being filled in — the client mirror of
 * PerformanceScorer::score(). A section's attainment is the weighted mean of its
 * scored lines' positions on their own scales; the overall is the weighted mean
 * of the sections. A section carrying no weight of its own falls back to the
 * weight of its lines, which makes an unsectioned card a flat weighted average.
 */
export function computeResult(
    lines: ScorableLine[],
    bands: RatingBand[],
): ScoreResult {
    const groups = new Map<string, ScorableLine[]>();

    for (const line of lines) {
        const key = line.section_key || 'overall';
        groups.set(key, [...(groups.get(key) ?? []), line]);
    }

    const sections: SectionResult[] = [...groups.entries()].map(
        ([key, group]) => {
            const scored = group.filter((line) => line.score !== null);
            const declared = Math.max(
                ...group.map((line) => line.section_weight || 0),
            );
            const lineWeight = scored.reduce(
                (sum, line) => sum + (line.weight || 0),
                0,
            );

            const fraction =
                scored.length === 0
                    ? null
                    : lineWeight > 0
                      ? scored.reduce(
                            (sum, line) =>
                                sum +
                                (scaleFraction(line) ?? 0) * (line.weight || 0),
                            0,
                        ) / lineWeight
                      : scored.reduce(
                            (sum, line) => sum + (scaleFraction(line) ?? 0),
                            0,
                        ) / scored.length;

            return {
                key,
                name: group[0].section_name,
                weight: declared > 0 ? declared : lineWeight,
                percent: fraction === null ? null : round(fraction * 100),
                scored: scored.length,
                total: group.length,
            };
        },
    );

    const contributing = sections.filter((section) => section.percent !== null);
    const scored = sections.reduce((sum, section) => sum + section.scored, 0);

    if (contributing.length === 0) {
        return {
            percent: null,
            normalized: null,
            band: null,
            sections,
            scored: 0,
            total: lines.length,
        };
    }

    const weight = contributing.reduce(
        (sum, section) => sum + section.weight,
        0,
    );

    const percent = round(
        weight > 0
            ? contributing.reduce(
                  (sum, section) =>
                      sum + (section.percent ?? 0) * section.weight,
                  0,
              ) / weight
            : contributing.reduce(
                  (sum, section) => sum + (section.percent ?? 0),
                  0,
              ) / contributing.length,
    );

    return {
        percent,
        normalized: round(
            RATING_MIN + (percent / 100) * (RATING_MAX - RATING_MIN),
        ),
        band: bandFor(percent, bands),
        sections,
        scored,
        total: lines.length,
    };
}

function round(value: number): number {
    return Math.round(value * 100) / 100;
}

// ── Reviews, goals and calibration (ADRs 0072, 0073) ────────────────────────

/** Who a reviewer is to the person appraised, as a label and as a phrase. */
export const RELATIONSHIP_LABELS: Record<
    ReviewRelationship,
    { label: string; review: string }
> = {
    self: { label: 'Self', review: 'Self-review' },
    manager: { label: 'Manager', review: 'Manager review' },
    peer: { label: 'Peer', review: 'Peer review' },
    direct_report: { label: 'Direct report', review: 'Upward review' },
};

export const REVIEW_STATUS: Record<
    ReviewStatus,
    { label: string; className: string }
> = {
    pending: {
        label: 'Waiting',
        className:
            'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    },
    submitted: {
        label: 'Answered',
        className:
            'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    declined: {
        label: 'Declined',
        className:
            'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-400',
    },
    cancelled: {
        label: 'Closed',
        className: 'border-border bg-muted text-muted-foreground',
    },
};

/** How a goal is going — one palette for the chip, the bar and the dot. */
export const GOAL_HEALTH: Record<
    GoalHealth,
    { label: string; className: string; bar: string; dot: string }
> = {
    on_track: {
        label: 'On track',
        className:
            'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
        bar: 'bg-emerald-500',
        dot: 'bg-emerald-500',
    },
    at_risk: {
        label: 'At risk',
        className:
            'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
        bar: 'bg-amber-500',
        dot: 'bg-amber-500',
    },
    off_track: {
        label: 'Off track',
        className:
            'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-400',
        bar: 'bg-rose-500',
        dot: 'bg-rose-500',
    },
};

export const GOAL_STATUS: Record<
    GoalStatus,
    { label: string; className: string }
> = {
    active: {
        label: 'Active',
        className:
            'border-[#0ABFBF]/30 bg-[#0ABFBF]/10 text-[#0a7d82] dark:text-[#3fd6d6]',
    },
    achieved: {
        label: 'Achieved',
        className:
            'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    missed: {
        label: 'Missed',
        className:
            'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-400',
    },
    dropped: {
        label: 'Dropped',
        className: 'border-border bg-muted text-muted-foreground',
    },
};

export const SESSION_STATUS: Record<
    SessionStatus,
    { label: string; className: string }
> = {
    open: {
        label: 'Open',
        className:
            'border-[#0ABFBF]/30 bg-[#0ABFBF]/10 text-[#0a7d82] dark:text-[#3fd6d6]',
    },
    completed: {
        label: 'Completed',
        className:
            'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    cancelled: {
        label: 'Cancelled',
        className: 'border-border bg-muted text-muted-foreground',
    },
};

/** A multi-line field, styled as the app's other text areas. */
export const TEXTAREA =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none aria-invalid:border-destructive';

/** The shape every status pill in the module shares. */
export const PILL =
    'inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-[11px] font-medium';

/** Format an ISO timestamp as "Jun 16, 2026". */
export function formatTimestamp(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

/** "today", "3 days ago", "Jun 2" — how long since something happened. */
export function sinceLabel(iso: string | null): string {
    if (!iso) {
        return 'never';
    }

    const days = Math.floor(
        (Date.now() - new Date(iso).getTime()) / 86_400_000,
    );

    if (days <= 0) {
        return 'today';
    }

    if (days === 1) {
        return 'yesterday';
    }

    if (days < 30) {
        return `${days} days ago`;
    }

    return formatTimestamp(iso);
}
