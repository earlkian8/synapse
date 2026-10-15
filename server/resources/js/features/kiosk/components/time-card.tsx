import { cn } from '@/lib/utils';
import { PUNCH_WORDS } from '../constants';
import { clockTime } from '../hooks/use-kiosk-clock';
import type { KioskDay } from '../types';

/**
 * The person's time card for the day: each punch stamped in its column's ink,
 * and the empty line the next one will land on. The punch just made is stamped
 * on as it happens — the kiosk's one flourish.
 */
export function TimeCard({
    day,
    timeZone,
    stampedAt,
}: {
    day: KioskDay;
    timeZone: string;
    /** The punch just recorded, stamped on with a press. */
    stampedAt?: string;
}) {
    const upcoming = day.next_expected;

    return (
        <ol className="divide-y divide-(--k-rule) border-y border-(--k-rule)">
            {day.punches.map((punch) => {
                const words = PUNCH_WORDS[punch.type];
                const fresh = stampedAt !== undefined && punch.at === stampedAt;

                return (
                    <li
                        key={`${punch.type}-${punch.at}`}
                        className="flex h-12 items-center justify-between gap-4 px-1"
                    >
                        <span className="text-sm text-(--k-muted)">
                            {words.column}
                        </span>
                        <span
                            style={{ color: words.ink }}
                            className={cn(
                                'inline-block -rotate-2 rounded-md border-2 border-current px-2 py-0.5 text-lg font-semibold tabular-nums opacity-90',
                                fresh &&
                                    'motion-safe:animate-[kiosk-stamp_460ms_cubic-bezier(.2,.9,.3,1.15)_both]',
                            )}
                        >
                            {clockTime(punch.at, timeZone)}
                        </span>
                    </li>
                );
            })}
            {upcoming && (
                <li className="flex h-12 items-center justify-between gap-4 px-1">
                    <span className="text-sm text-(--k-muted)">
                        {PUNCH_WORDS[upcoming].column}
                    </span>
                    <span
                        aria-label="Not yet"
                        className="h-0 w-24 border-t-2 border-dashed border-(--k-rule)"
                    />
                </li>
            )}
        </ol>
    );
}
