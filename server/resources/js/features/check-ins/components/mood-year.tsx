import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { MOOD_WORDS, monthName, monthShort } from '../constants';
import type { MyCheckIn } from '../types';
import { MoodFace } from './mood-face';

/**
 * Your year in moods (ADR 0102): the last twelve months as twelve faces, oldest
 * first, a month without a check-in drawn in outline. Choosing a month opens what
 * was answered then. Only ever the person's own.
 */
export function MoodYear({
    months,
    history,
    selected,
    current,
    onSelect,
}: {
    /** YYYY-MM-DD month starts, oldest first. */
    months: string[];
    history: MyCheckIn[];
    selected: string | null;
    /** This month, which may still be waiting. */
    current: string;
    onSelect: (period: string) => void;
}) {
    const byPeriod = Object.fromEntries(history.map((c) => [c.period, c]));

    return (
        <div>
            <ol
                className="grid grid-cols-12 gap-0.5 sm:gap-1"
                aria-label="Your last twelve months"
            >
                {months.map((period, index) => {
                    const checkIn = byPeriod[period];
                    const year = period.slice(0, 4);
                    const firstOfYear =
                        index === 0 || months[index - 1].slice(0, 4) !== year;
                    const isSelected = selected === period;

                    return (
                        <li key={period} className="flex flex-col items-center">
                            <span
                                className={cn(
                                    'mb-1 h-4 self-start text-[10px] text-muted-foreground tabular-nums',
                                    !firstOfYear && 'invisible',
                                )}
                            >
                                {year}
                            </span>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            checkIn && onSelect(period)
                                        }
                                        disabled={!checkIn}
                                        aria-pressed={isSelected}
                                        aria-label={
                                            checkIn
                                                ? `${monthName(period, true)}: ${MOOD_WORDS[checkIn.answers.mood - 1]}`
                                                : `${monthName(period, true)}: no check-in`
                                        }
                                        className={cn(
                                            'flex w-full flex-col items-center gap-1 rounded-lg py-1.5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                            checkIn && 'hover:bg-muted/60',
                                            isSelected && 'bg-muted',
                                        )}
                                    >
                                        <MoodFace
                                            mood={checkIn?.answers.mood ?? null}
                                            size={38}
                                            className="size-6 sm:size-[38px]"
                                            label=""
                                        />
                                        <span
                                            className={cn(
                                                'text-[11px]',
                                                isSelected
                                                    ? 'font-semibold text-foreground'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {monthShort(period)}
                                        </span>
                                        <span
                                            aria-hidden
                                            className={cn(
                                                'size-1 rounded-full',
                                                period === current && !checkIn
                                                    ? 'bg-foreground'
                                                    : 'bg-transparent',
                                            )}
                                        />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent side="top">
                                    {checkIn ? (
                                        <>
                                            <p className="font-semibold">
                                                {
                                                    MOOD_WORDS[
                                                        checkIn.answers.mood - 1
                                                    ]
                                                }
                                            </p>
                                            <p className="opacity-80">
                                                {monthName(period, true)} ·
                                                engagement{' '}
                                                {checkIn.engagement.toFixed(1)}{' '}
                                                of 6
                                            </p>
                                        </>
                                    ) : period === current ? (
                                        'Still to answer this month'
                                    ) : (
                                        `No check-in in ${monthName(period)}`
                                    )}
                                </TooltipContent>
                            </Tooltip>
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}
