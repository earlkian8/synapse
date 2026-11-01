import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { formatPoints, POLARITY } from '../lib';
import { useRevealed } from '../use-revealed';

export type LedgerStep = {
    key: string;
    label: string;
    /** The employee's value against the typical one, in words. */
    detail?: ReactNode;
    /** Signed points this reason moves the score. */
    points: number;
    /** Whether moving the score this way is good news for the employee. */
    favourable: boolean;
};

/**
 * A score as a walk from a typical record to this one: the typical score, then each
 * reason's points (exact Shapley contributions, so they add up), then the score
 * itself. Every row is drawn on the same 0–100 track, with hairlines where the tiers
 * begin, so a reader sees both how far each reason moved the score and which side
 * of a tier line it ended on.
 */
export function ContributionLedger({
    start,
    steps,
    end,
    guides = [],
    label,
}: {
    start: { label: string; detail?: ReactNode; value: number };
    steps: LedgerStep[];
    end: { label: string; detail?: ReactNode; value: number; fill: string };
    guides?: { value: number; label: string }[];
    /** What the ledger explains, for screen readers. */
    label: string;
}) {
    const revealed = useRevealed();
    const shown = steps.reduce((sum, step) => sum + step.points, 0);
    // Effects under the model's floor are not listed one by one, but they are part
    // of the score: show them together so the walk still ends where the score is.
    const rest = end.value - start.value - shown;
    const rows = [
        ...steps,
        ...(Math.abs(rest) >= 0.5
            ? [
                  {
                      key: '__rest',
                      label: 'Smaller effects',
                      detail: 'Every other input together',
                      points: rest,
                      favourable: false,
                      neutral: true,
                  },
              ]
            : []),
    ] as (LedgerStep & { neutral?: boolean })[];

    const runningTotals = rows.reduce<number[]>(
        (acc, row) => [...acc, (acc.at(-1) ?? start.value) + row.points],
        [],
    );

    const bar = (from: number, to: number) => {
        const lo = Math.max(0, Math.min(from, to));
        const hi = Math.min(100, Math.max(from, to));

        return { left: `${lo}%`, width: `max(2px, ${hi - lo}%)` };
    };

    return (
        <figure aria-label={label} className="flex flex-col">
            {/* Scale header: the tier lines, named once. */}
            <div className="grid grid-cols-[minmax(0,1.4fr)_2.75rem_minmax(0,1fr)] gap-x-3 pb-1 sm:grid-cols-[minmax(0,1fr)_2.75rem_minmax(0,1.2fr)]">
                <span />
                <span />
                <div className="relative h-3.5 text-[10px] text-muted-foreground tabular-nums">
                    <span className="absolute left-0">0</span>
                    {guides.map((g) => (
                        <span
                            key={g.label}
                            className="absolute -translate-x-1/2 whitespace-nowrap"
                            style={{ left: `${g.value}%` }}
                        >
                            {Math.round(g.value)}
                        </span>
                    ))}
                    <span className="absolute right-0">100</span>
                </div>
            </div>

            <ol className="flex flex-col">
                <Row
                    label={start.label}
                    detail={start.detail}
                    value={String(Math.round(start.value))}
                    guides={guides}
                >
                    <span
                        className="absolute inset-y-1 rounded-r-[4px] bg-muted-foreground/35 transition-[width] duration-500 ease-out"
                        style={{
                            left: 0,
                            width: revealed ? `${start.value}%` : 0,
                        }}
                    />
                </Row>

                {rows.map((row, i) => {
                    const from = i === 0 ? start.value : runningTotals[i - 1];
                    const to = runningTotals[i];
                    const polarity = row.favourable
                        ? POLARITY.favourable
                        : POLARITY.unfavourable;
                    const up = row.points >= 0;

                    return (
                        <Row
                            key={row.key}
                            label={row.label}
                            detail={row.detail}
                            value={formatPoints(row.points)}
                            icon={
                                row.neutral ? null : up ? (
                                    <ArrowUpRight
                                        className={cn(
                                            'size-3.5',
                                            row.favourable
                                                ? 'text-teal-600'
                                                : 'text-rose-600 dark:text-rose-500',
                                        )}
                                    />
                                ) : (
                                    <ArrowDownRight
                                        className={cn(
                                            'size-3.5',
                                            row.favourable
                                                ? 'text-teal-600'
                                                : 'text-rose-600 dark:text-rose-500',
                                        )}
                                    />
                                )
                            }
                            guides={guides}
                        >
                            <span
                                className={cn(
                                    'absolute inset-y-1 rounded-[4px] transition-opacity duration-300',
                                    row.neutral
                                        ? 'bg-muted-foreground/35'
                                        : polarity.fill,
                                    revealed ? 'opacity-100' : 'opacity-0',
                                )}
                                style={{
                                    ...bar(from, to),
                                    transitionDelay: `${150 + i * 90}ms`,
                                }}
                            />
                        </Row>
                    );
                })}

                <Row
                    label={end.label}
                    detail={end.detail}
                    value={String(Math.round(end.value))}
                    guides={guides}
                    total
                >
                    <span
                        className={cn(
                            'absolute inset-y-1 rounded-r-[4px] transition-[width] duration-700 ease-out',
                            end.fill,
                        )}
                        style={{
                            left: 0,
                            width: revealed ? `${end.value}%` : 0,
                            transitionDelay: `${200 + rows.length * 90}ms`,
                        }}
                    />
                </Row>
            </ol>
        </figure>
    );
}

function Row({
    label,
    detail,
    value,
    icon,
    guides,
    total = false,
    children,
}: {
    label: string;
    detail?: ReactNode;
    value: string;
    icon?: ReactNode;
    guides: { value: number; label: string }[];
    total?: boolean;
    children: ReactNode;
}) {
    return (
        <li
            className={cn(
                'grid grid-cols-[minmax(0,1.4fr)_2.75rem_minmax(0,1fr)] items-center gap-x-3 py-1.5 sm:grid-cols-[minmax(0,1fr)_2.75rem_minmax(0,1.2fr)]',
                total && 'mt-1 border-t border-border pt-2.5',
            )}
        >
            <div className="flex min-w-0 flex-col">
                <span
                    className={cn(
                        'text-sm break-words',
                        total && 'font-semibold',
                    )}
                >
                    {label}
                </span>
                {detail && (
                    <span className="text-xs text-muted-foreground">
                        {detail}
                    </span>
                )}
            </div>
            <span
                className={cn(
                    'flex items-center justify-end gap-0.5 text-sm tabular-nums',
                    total ? 'font-semibold' : 'font-medium',
                )}
            >
                {icon}
                {value}
            </span>
            <div className="relative h-6 rounded-sm bg-muted/50">
                {guides.map((g) => (
                    <span
                        key={g.label}
                        aria-hidden
                        className="absolute inset-y-0 border-l border-foreground/20"
                        style={{ left: `${g.value}%` }}
                    />
                ))}
                {children}
            </div>
        </li>
    );
}
