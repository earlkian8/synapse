import { cn } from '@/lib/utils';
import { formatMoney } from '../constants';

/**
 * Spent against a budget, as one bar (ADR 0075): teal while there is room,
 * amber from 90%, rose once it is over — always with the figures in words, so
 * the colour is never the only signal. With no budget there is nothing to
 * measure against, and it says so.
 */
export function BudgetMeter({
    spent,
    budget,
    className,
}: {
    spent: number;
    budget: number | null;
    className?: string;
}) {
    if (budget === null || budget <= 0) {
        return (
            <p className={cn('text-sm text-muted-foreground', className)}>
                {formatMoney(spent)} spent · no budget set
            </p>
        );
    }

    const used = Math.round((spent / budget) * 100);
    const over = spent > budget;
    const tone = over
        ? 'bg-rose-500'
        : used >= 90
          ? 'bg-amber-500'
          : 'bg-[#0ABFBF]';

    return (
        <div className={cn('flex flex-col gap-1.5', className)}>
            <div
                className="h-2 overflow-hidden rounded-full bg-muted"
                role="meter"
                aria-valuemin={0}
                aria-valuemax={budget}
                aria-valuenow={Math.min(spent, budget)}
                aria-label="Budget used"
            >
                <div
                    className={cn('h-full rounded-full', tone)}
                    style={{ width: `${Math.min(100, Math.max(used, 2))}%` }}
                />
            </div>
            <p className="flex flex-wrap justify-between gap-x-3 text-xs text-muted-foreground tabular-nums">
                <span>
                    <span className="font-semibold text-foreground">
                        {formatMoney(spent)}
                    </span>{' '}
                    of {formatMoney(budget)} ({used}%)
                </span>
                <span
                    className={cn(
                        over && 'font-medium text-rose-600 dark:text-rose-400',
                    )}
                >
                    {over
                        ? `${formatMoney(spent - budget)} over`
                        : `${formatMoney(budget - spent)} left`}
                </span>
            </p>
        </div>
    );
}
