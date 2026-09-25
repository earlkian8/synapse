import type {
    FieldState,
    ModelKey,
    RequirementGroup,
    RequirementStatus,
    Stage,
} from './types';

/**
 * The three stages, in order. A surface starts on a borrowed model, spends a long
 * time collecting local history, and only then trains on it.
 */
export const STAGE_ORDER: Stage[] = ['provisional', 'collecting', 'graduated'];

export const STAGE_LABELS: Record<Stage, string> = {
    provisional: 'Provisional',
    collecting: 'Collecting',
    graduated: 'Graduated',
};

/**
 * Per-surface copy. Deliberately free of model internals — which algorithm runs
 * and how accurate it tested are implementation details this screen's audience is
 * not meant to reason about (see the surfaces' own ServiceBanner). What an HR
 * reader *does* need is whose workforce the scores actually describe.
 */
export const MODEL_COPY: Record<
    ModelKey,
    { title: string; provenance: string; stages: Record<Stage, string> }
> = {
    promotion: {
        title: 'Where these readiness scores come from',
        provenance:
            'A general workforce dataset — not this organisation’s own promotion history.',
        stages: {
            provisional:
                'Scoring from a general workforce dataset. Every score is marked provisional.',
            collecting:
                'Still scoring provisionally, but now recording each score against who was actually promoted.',
            graduated:
                'Built from this organisation’s own promotion history. Scores describe this workforce.',
        },
    },
    performance: {
        title: 'Where these forecasts come from',
        provenance:
            'A general workforce dataset — not this organisation’s own appraisal history.',
        stages: {
            provisional:
                'Forecasting from a general workforce dataset. Every forecast is marked provisional.',
            collecting:
                'Still forecasting provisionally, but now recording each forecast against the rating that followed.',
            graduated:
                'Built from this organisation’s own appraisal history. Forecasts describe this workforce.',
        },
    },
    attrition: {
        title: 'Where these risk scores come from',
        provenance:
            'A survey of workers at other employers about why they stayed or left — not this organisation’s own departure history.',
        stages: {
            provisional:
                'Scoring from an outside survey of workers. Every score is marked provisional.',
            collecting:
                'Still scoring provisionally, but now recording each score against who actually left.',
            graduated:
                'Built from this organisation’s own departure history. Scores describe this workforce.',
        },
    },
};

/**
 * A locked gate is the system working, not an error — so the palette stays in the
 * neutral/teal family and never reaches for a destructive red.
 */
export const STATUS_LABELS: Record<RequirementStatus, string> = {
    met: 'Met',
    progressing: 'In progress',
    waiting: 'Not started',
};

export const STATUS_STYLES: Record<RequirementStatus, string> = {
    met: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    progressing:
        'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    waiting:
        'border-sidebar-border/70 bg-muted text-muted-foreground dark:border-sidebar-border',
};

/** Bar fill per status, matching the badge palette. */
export const STATUS_BARS: Record<RequirementStatus, string> = {
    met: 'bg-emerald-500',
    progressing: 'bg-amber-500',
    waiting: 'bg-muted-foreground/30',
};

export const GROUP_LABELS: Record<RequirementGroup, string> = {
    volume: 'Enough history',
    quality: 'History that means the same thing',
    system: 'System readiness',
};

/** Groups in display order. */
export const GROUP_ORDER: RequirementGroup[] = ['volume', 'quality', 'system'];

/**
 * Field states, ordered so the reader meets what works before what is missing.
 * `available` sits in the middle deliberately: it is the most actionable of the
 * three, because the data already exists and only the wiring is absent.
 */
export const FIELD_STATE_ORDER: FieldState[] = [
    'supplied',
    'available',
    'missing',
];

export const FIELD_STATE_LABELS: Record<FieldState, string> = {
    supplied: 'Used now',
    available: 'Recorded, not used',
    missing: 'Not recorded anywhere',
};

export const FIELD_STATE_HINTS: Record<FieldState, string> = {
    supplied: 'Read from your records and fed into every score.',
    available:
        'The system already holds these — wiring them in needs no new data entry.',
    missing:
        'No part of the system produces these, so they cannot be filled in.',
};

export const FIELD_STATE_STYLES: Record<FieldState, string> = {
    supplied:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    available: 'border-[#0ABFBF]/30 bg-[#0ABFBF]/10 text-[#0ABFBF]',
    missing:
        'border-sidebar-border/70 bg-muted text-muted-foreground dark:border-sidebar-border',
};

export const FIELD_STATE_BARS: Record<FieldState, string> = {
    supplied: 'bg-emerald-500',
    available: 'bg-[#0ABFBF]',
    missing: 'bg-muted-foreground/30',
};

/** Format a count against its requirement, e.g. "14 / 120". */
export function formatProgress(current: number, required: number): string {
    return `${current.toLocaleString()} / ${required.toLocaleString()}`;
}

/** A requirement's completion as a 0–100 percentage, capped. */
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

/** Compact relative time, e.g. "3h ago", "2d ago". */
export function formatRelative(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);

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

    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}
