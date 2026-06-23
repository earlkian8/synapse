import type { ProgramStatus, TrainingEnrollmentStatus } from './types';

export const PROGRAM_STATUS_LABELS: Record<ProgramStatus, string> = {
    upcoming: 'Upcoming',
    ongoing: 'Ongoing',
    completed: 'Completed',
};

export const PROGRAM_STATUS_STYLES: Record<ProgramStatus, string> = {
    upcoming: 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400',
    ongoing:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    completed: 'border-border bg-muted text-muted-foreground',
};

/** Display order for the program sections on the overview. */
export const PROGRAM_STATUS_ORDER: ProgramStatus[] = [
    'ongoing',
    'upcoming',
    'completed',
];

export const ENROLLMENT_STATUS_LABELS: Record<
    TrainingEnrollmentStatus,
    string
> = {
    enrolled: 'Enrolled',
    completed: 'Completed',
    dropped: 'Dropped',
};

export const ENROLLMENT_STATUS_STYLES: Record<
    TrainingEnrollmentStatus,
    string
> = {
    enrolled: 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400',
    completed:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    dropped:
        'border-slate-500/25 bg-slate-500/10 text-slate-600 dark:text-slate-300',
};

/** Format a completion score out of 100, e.g. "85%". */
export function formatScore(score: number | null): string {
    return score === null
        ? '—'
        : `${Number.isInteger(score) ? score : score.toFixed(1)}%`;
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

/** A human date window, e.g. "Jun 15 – 19, 2026", "From Jul 6, 2026", or "Self-paced". */
export function formatDateRange(
    start: string | null,
    end: string | null,
): string {
    if (!start && !end) {
        return 'Self-paced';
    }

    if (start && !end) {
        return `From ${formatDate(start)}`;
    }

    if (!start && end) {
        return `Until ${formatDate(end)}`;
    }

    return `${formatDate(start)} – ${formatDate(end)}`;
}
