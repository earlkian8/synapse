import { CalendarClock, CalendarX2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ManagedPosting } from '../types';

/**
 * A posting's closing date rendered as a deadline cue: an "Expired" flag once an
 * open posting is past due, a tight countdown when the deadline is near, or the
 * plain date otherwise. Returns a muted dash when no closing date is set.
 */
export function PostingDeadline({
    posting,
    className,
}: {
    posting: Pick<
        ManagedPosting,
        'closing_date' | 'days_to_close' | 'is_expired' | 'status'
    >;
    className?: string;
}) {
    if (!posting.closing_date) {
        return <span className="text-muted-foreground">—</span>;
    }

    if (posting.is_expired) {
        return (
            <span
                className={cn(
                    'inline-flex items-center gap-1 text-xs font-medium text-rose-600 dark:text-rose-400',
                    className,
                )}
            >
                <CalendarX2 className="size-3.5" />
                Expired
            </span>
        );
    }

    const days = posting.days_to_close;
    const soon = posting.status === 'open' && days !== null && days <= 7;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 text-xs',
                soon
                    ? 'font-medium text-amber-600 dark:text-amber-400'
                    : 'text-muted-foreground',
                className,
            )}
        >
            <CalendarClock className="size-3.5" />
            {posting.status === 'open' && days !== null
                ? days <= 0
                    ? 'Closes today'
                    : `Closes in ${days}d`
                : posting.closing_date}
        </span>
    );
}
