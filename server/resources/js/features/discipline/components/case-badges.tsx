import { cn } from '@/lib/utils';
import {
    GRAVITY_LABELS,
    GRAVITY_STEPS,
    STANDING_TONES,
    STATUS_LABELS,
    STATUS_TONES,
} from '../constants';
import { calendarDate } from '../constants';
import type { CaseStatus, Gravity, Standing } from '../types';

export function StatusBadge({
    status,
    overdue = false,
}: {
    status: CaseStatus;
    overdue?: boolean;
}) {
    return (
        <span
            className={cn(
                'inline-flex rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                overdue
                    ? 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300'
                    : STATUS_TONES[status],
            )}
        >
            {overdue ? 'No answer by the due date' : STATUS_LABELS[status]}
        </span>
    );
}

/**
 * How grave an offense is, as four ticks that fill — readable at a glance in a
 * list, and said in words for a screen reader.
 */
export function GravityMark({
    gravity,
    withLabel = false,
}: {
    gravity: Gravity;
    withLabel?: boolean;
}) {
    const steps = GRAVITY_STEPS[gravity];

    return (
        <span
            className="inline-flex items-center gap-1.5"
            title={GRAVITY_LABELS[gravity]}
        >
            <span aria-hidden className="inline-flex items-end gap-0.5">
                {[1, 2, 3, 4].map((step) => (
                    <span
                        key={step}
                        className={cn(
                            'w-1 rounded-full',
                            step <= steps
                                ? steps >= 4
                                    ? 'bg-rose-500'
                                    : steps === 3
                                      ? 'bg-amber-500'
                                      : 'bg-slate-500 dark:bg-slate-400'
                                : 'bg-border',
                        )}
                        style={{ height: 4 + step * 2 }}
                    />
                ))}
            </span>
            <span
                className={
                    withLabel ? 'text-xs text-muted-foreground' : 'sr-only'
                }
            >
                {GRAVITY_LABELS[gravity]}
            </span>
        </span>
    );
}

export function StandingBadge({ standing }: { standing: Standing }) {
    return (
        <span
            className={cn(
                'inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium',
                STANDING_TONES[standing.key],
            )}
        >
            {standing.label}
            {standing.until && ` until ${calendarDate(standing.until)}`}
        </span>
    );
}
