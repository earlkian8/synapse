import type { CaseStatus, Gravity, Penalty, Standing } from './types';

export const STATUS_LABELS: Record<CaseStatus, string> = {
    reported: 'Reported',
    notice_issued: 'Awaiting explanation',
    explained: 'Explained',
    hearing: 'Hearing',
    decided: 'Decided',
    closed: 'Closed',
    withdrawn: 'Withdrawn',
};

export const STATUS_TONES: Record<CaseStatus, string> = {
    reported: 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300',
    notice_issued:
        'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    explained:
        'border-violet-500/30 bg-violet-500/10 text-violet-700 dark:text-violet-300',
    hearing:
        'border-indigo-500/30 bg-indigo-500/10 text-indigo-700 dark:text-indigo-300',
    decided:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    closed: 'border-border bg-muted text-muted-foreground',
    withdrawn: 'border-border bg-muted text-muted-foreground',
};

export const GRAVITY_LABELS: Record<Gravity, string> = {
    minor: 'Minor',
    less_serious: 'Less serious',
    serious: 'Serious',
    grave: 'Grave',
};

/** Gravity as weight: a short bar that fills as the offense grows graver. */
export const GRAVITY_STEPS: Record<Gravity, number> = {
    minor: 1,
    less_serious: 2,
    serious: 3,
    grave: 4,
};

export const PENALTY_LABELS: Record<Penalty, string> = {
    verbal_warning: 'Verbal reprimand',
    written_warning: 'Written warning',
    final_warning: 'Final written warning',
    suspension: 'Suspension without pay',
    dismissal: 'Dismissal',
};

export const STANDING_TONES: Record<Standing['key'], string> = {
    dismissed:
        'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300',
    suspended:
        'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300',
    preventive:
        'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    final_warning:
        'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    warned: 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    under_review:
        'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300',
    good: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
};

/** A penalty step in words: "Suspension without pay, 3 days". */
export function penaltyStep(step: {
    action: Penalty;
    days?: number | null;
}): string {
    return step.action === 'suspension' && step.days
        ? `Suspension, ${step.days} ${step.days === 1 ? 'day' : 'days'}`
        : PENALTY_LABELS[step.action];
}

/** "Dec 24, 2026" for a calendar date, no zone shift. */
export function calendarDate(date: string | null): string {
    if (!date) {
        return '—';
    }

    const [y, m, d] = date.slice(0, 10).split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

/** "Dec 24, 2026, 9:30 AM" for an instant, on the company's clock. */
export function dateTime(iso: string | null, timeZone?: string): string {
    return iso
        ? new Date(iso).toLocaleString('en-US', {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
              timeZone,
          })
        : '—';
}

/** Whole days from one calendar date to another. */
export function daysBetween(from: string, to: string): number {
    return Math.round(
        (Date.parse(`${to.slice(0, 10)}T00:00:00Z`) -
            Date.parse(`${from.slice(0, 10)}T00:00:00Z`)) /
            86_400_000,
    );
}
