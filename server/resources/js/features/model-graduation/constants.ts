import type {
    Comparison,
    FieldState,
    ModelKey,
    Requirement,
    RequirementGroup,
    RequirementStatus,
    Stage,
} from './types';

/** The three stages, in order. */
export const STAGE_ORDER: Stage[] = ['provisional', 'collecting', 'graduated'];

/**
 * Each stage in plain words. The labels name what is *scoring*, which is the
 * question a reader actually has — not the lifecycle's internal vocabulary.
 */
export const STAGE_COPY: Record<Stage, { label: string; description: string }> =
    {
        provisional: {
            label: 'General model',
            description:
                'Scores come from a model built on data from other workplaces. Nothing it could learn from here is recorded yet.',
        },
        collecting: {
            label: 'Collecting your history',
            description:
                'Still the general model, while your own records build up towards what a model of your own needs.',
        },
        graduated: {
            label: 'Your own model',
            description:
                'Scores come from a model trained on your records, which proved more accurate for your people.',
        },
    };

/** Per-surface wording, so every sentence names what this page actually scores. */
export const MODEL_COPY: Record<
    ModelKey,
    {
        /** The page the surface lives on. */
        page: string;
        /** "readiness scores" — what the page shows. */
        scores: string;
        /** Where the general model's data came from. */
        general: string;
        /** What its own model learns from. */
        learnsFrom: string;
        /** What "the rarer outcome" is for its check. */
        outcome: string;
    }
> = {
    promotion: {
        page: 'Promotion Readiness',
        scores: 'readiness scores',
        general:
            'a general workforce dataset of employees at other organisations',
        learnsFrom: 'who has actually been promoted here',
        outcome: 'promoted',
    },
    performance: {
        page: 'Performance Forecast',
        scores: 'forecasts',
        general:
            'a general workforce dataset of employees at other organisations',
        learnsFrom:
            'how ratings here have actually moved from one cycle to the next',
        outcome: 'next rating',
    },
    attrition: {
        page: 'Attrition Risk',
        scores: 'risk scores',
        general:
            'a survey of workers at other employers about why they stayed or left',
        learnsFrom: 'who has actually resigned here',
        outcome: 'resigned',
    },
};

export const STATUS_LABELS: Record<RequirementStatus, string> = {
    met: 'Done',
    progressing: 'In progress',
    waiting: 'Not started',
};

/**
 * A locked gate is the system working, not an error — so nothing here reaches for
 * a destructive red. Status always ships with an icon and a label, never colour
 * alone.
 */
export const STATUS_STYLES: Record<RequirementStatus, string> = {
    met: 'text-emerald-600 dark:text-emerald-400',
    progressing: 'text-amber-600 dark:text-amber-400',
    waiting: 'text-muted-foreground',
};

/** Meter fill, and its track — a lighter step of the same hue. */
export const STATUS_BARS: Record<RequirementStatus, string> = {
    met: 'bg-emerald-500',
    progressing: 'bg-amber-500',
    waiting: 'bg-muted-foreground/40',
};

export const STATUS_TRACKS: Record<RequirementStatus, string> = {
    met: 'bg-emerald-500/15',
    progressing: 'bg-amber-500/15',
    waiting: 'bg-muted',
};

export const GROUP_COPY: Record<
    RequirementGroup,
    { label: string; hint: string }
> = {
    volume: {
        label: 'Enough history',
        hint: 'A model needs plenty of real examples from your records to learn from — and to be tested on.',
    },
    quality: {
        label: 'History that can be trusted',
        hint: 'The examples have to mean the same thing from one record to the next.',
    },
    system: {
        label: 'The system',
        hint: 'What has to be running for training to happen.',
    },
};

/** Groups in display order. */
export const GROUP_ORDER: RequirementGroup[] = ['volume', 'quality', 'system'];

/**
 * Field states, ordered so the reader meets what works before what is missing.
 */
export const FIELD_STATE_ORDER: FieldState[] = [
    'supplied',
    'available',
    'missing',
];

export const FIELD_STATE_LABELS: Record<FieldState, string> = {
    supplied: 'Used in every score',
    available: 'Recorded, but not used',
    missing: 'Not recorded anywhere',
};

export const FIELD_STATE_HINTS: Record<FieldState, string> = {
    supplied: 'Read from your records each time the page is run.',
    available:
        'The system holds these, and each says why it is left out of the scores.',
    missing: 'No part of the system produces these, so they can’t be used.',
};

export const FIELD_STATE_STYLES: Record<FieldState, string> = {
    supplied:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    available:
        'border-[#0ABFBF]/30 bg-[#0ABFBF]/10 text-teal-700 dark:text-[#0ABFBF]',
    missing:
        'border-sidebar-border/70 bg-muted text-muted-foreground dark:border-sidebar-border',
};

export const FIELD_STATE_BARS: Record<FieldState, string> = {
    supplied: 'bg-emerald-500',
    available: 'bg-[#0ABFBF]',
    missing: 'bg-muted-foreground/30',
};

export const FIELD_STATE_TRACKS: Record<FieldState, string> = {
    supplied: 'bg-emerald-500/15',
    available: 'bg-[#0ABFBF]/15',
    missing: 'bg-muted',
};

/** A requirement's completion, 0–100, capped. */
export function completion(current: number, required: number): number {
    if (required <= 0) {
        return 100;
    }

    return Math.min(100, Math.round((current / required) * 100));
}

/** Pick the singular or plural wording for a count. */
export function pluralise(
    amount: number,
    unit: string,
    unitOne: string,
): string {
    return amount === 1 ? unitOne : unit;
}

/** "14 of 100", "72% of 80%", or "Yes" / "No". */
export function formatProgress(requirement: Requirement): string {
    const { current, required, format } = requirement;

    if (format === 'check') {
        return current >= required ? 'Ready' : 'Not ready';
    }

    if (format === 'percent') {
        return `${formatNumber(current)}% of ${formatNumber(required)}% needed`;
    }

    return `${current.toLocaleString()} of ${required.toLocaleString()}`;
}

/** What is still missing, in words: "86 more promotions", "8 points to go". */
export function formatShortfall(requirement: Requirement): string | null {
    const { current, required, format, unit, unit_one } = requirement;

    if (current >= required) {
        return null;
    }

    if (format === 'check') {
        return 'Not available right now';
    }

    if (format === 'percent') {
        return `${formatNumber(required - current)} percentage points to go`;
    }

    const remaining = Math.ceil(required - current);

    return `${remaining.toLocaleString()} more ${pluralise(remaining, unit, unit_one)}`;
}

function formatNumber(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

/** "27 Sep 2026". */
export function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/**
 * How each surface's check is read aloud. The measure differs per surface — the
 * right one for what it predicts — so each carries its own name, formatting and a
 * one-line explanation of which way is better.
 */
export const COMPARISON_COPY: Record<
    Comparison['metric'],
    {
        label: string;
        explain: string;
        format: (value: number) => string;
        baseline: string;
    }
> = {
    brier: {
        label: 'Prediction error',
        explain:
            'How far the predicted chances of promotion were from what actually happened. Lower is better.',
        format: (v) => v.toFixed(3),
        baseline: 'Assuming everyone has your usual promotion rate',
    },
    mae: {
        label: 'Average miss',
        explain:
            'How far forecasts were from the rating that actually followed, in points. Lower is better.',
        format: (v) => `${v.toFixed(1)} pts`,
        baseline: 'Repeating each person’s last rating',
    },
    roc_auc: {
        label: 'Tells leavers from stayers',
        explain:
            'How often someone who resigned was ranked above someone who stayed. 50% is a coin flip; higher is better.',
        format: (v) => `${Math.round(v * 100)}%`,
        baseline: 'A coin flip',
    },
};
