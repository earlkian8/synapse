import type { RequirementGroup, RequirementStatus, Stage } from './types';

/**
 * The three stages, in order. A model starts on a borrowed dataset, spends a
 * long time collecting local history, and only then trains on it.
 */
export const STAGE_ORDER: Stage[] = ['provisional', 'collecting', 'graduated'];

export const STAGE_LABELS: Record<Stage, string> = {
    provisional: 'Provisional',
    collecting: 'Collecting',
    graduated: 'Graduated',
};

export const STAGE_SUMMARIES: Record<Stage, string> = {
    provisional:
        'Scoring with a model trained on a general public dataset. Every prediction is labelled provisional.',
    collecting:
        'Still scoring provisionally, but now recording each prediction against what actually happened.',
    graduated:
        'Retrained on this organisation’s own records. Predictions describe this workforce, not a borrowed one.',
};

/**
 * A locked gate is the system working, not an error — so the palette stays in
 * the neutral/teal family and never reaches for a destructive red.
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
    volume: 'Enough data',
    quality: 'Trustworthy data',
    system: 'System readiness',
};

export const GROUP_SUMMARIES: Record<RequirementGroup, string> = {
    volume: 'Whether there is simply enough history to learn anything from.',
    quality:
        'Whether that history means the same thing from one cycle to the next.',
    system: 'Whether the application is set up to train and store a model safely.',
};

/** Groups in display order. */
export const GROUP_ORDER: RequirementGroup[] = ['volume', 'quality', 'system'];

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

    return formatDateTime(iso);
}

/**
 * How long the projection is away, in words — "about 15 years away". Kept vague
 * on purpose: the projection is a straight-line estimate, not a forecast.
 */
export function projectionDistance(year: number | null): string {
    if (year === null) {
        return 'not estimable yet';
    }

    const years = year - new Date().getFullYear();

    if (years <= 0) {
        return 'reachable now';
    }

    if (years === 1) {
        return 'about a year away';
    }

    return `about ${years} years away`;
}
