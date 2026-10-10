import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { formatMoney } from '../constants';

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

/**
 * Spend by month as twelve columns of one hue (ADR 0075) — magnitude only, so
 * no legend; the card's title names the series. Each column is a hover and
 * focus target taller than its mark, with the exact figure in a tooltip, and
 * the current month is labelled in full ink.
 */
export function MonthColumns({
    values,
    currentMonth,
}: {
    values: number[];
    /** 0–11 when the year shown is this one; the month is marked. */
    currentMonth: number | null;
}) {
    const max = Math.max(...values, 1);
    const empty = values.every((v) => v === 0);

    if (empty) {
        return (
            <p className="py-10 text-center text-sm text-muted-foreground">
                Nothing spent in this year yet.
            </p>
        );
    }

    return (
        <div
            role="list"
            aria-label="Spend by month"
            className="grid h-44 grid-cols-12 items-end gap-1.5"
        >
            {values.map((value, month) => (
                <Tooltip key={MONTHS[month]}>
                    <TooltipTrigger asChild>
                        <div
                            role="listitem"
                            tabIndex={0}
                            aria-label={`${MONTHS[month]}: ${formatMoney(value)}`}
                            className="group flex h-full min-w-0 flex-col items-center justify-end gap-1.5 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <div className="flex w-full flex-1 items-end justify-center">
                                <div
                                    className={cn(
                                        'w-full max-w-7 rounded-t-[4px] transition-opacity group-hover:opacity-80',
                                        value === 0
                                            ? 'bg-muted'
                                            : 'bg-[#0ABFBF]',
                                    )}
                                    style={{
                                        height:
                                            value === 0
                                                ? '2px'
                                                : `${Math.max((value / max) * 100, 3)}%`,
                                    }}
                                />
                            </div>
                            <span
                                className={cn(
                                    'text-[10px] tabular-nums',
                                    month === currentMonth
                                        ? 'font-semibold text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {MONTHS[month].charAt(0)}
                                <span className="hidden md:inline">
                                    {MONTHS[month].slice(1)}
                                </span>
                            </span>
                        </div>
                    </TooltipTrigger>
                    <TooltipContent>
                        {MONTHS[month]}: {formatMoney(value)}
                    </TooltipContent>
                </Tooltip>
            ))}
        </div>
    );
}
