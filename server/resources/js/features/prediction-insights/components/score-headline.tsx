import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { shortDate } from '../lib';

/**
 * The score at the top of a breakdown: the number, what it measures, its tier, a
 * 0–100 bar with the tier lines (and a likely range, for a forecast), how it moved
 * since the person's previous run, and one line on what the tier means.
 */
export function ScoreHeadline({
    value,
    unit,
    tone,
    fill,
    badge,
    guides,
    range,
    moved,
    upIsGood,
    children,
}: {
    value: number;
    /** e.g. "/ 100 flight risk". */
    unit: ReactNode;
    /** Text colour class for the number. */
    tone: string;
    /** Background class for the bar. */
    fill: string;
    badge: ReactNode;
    guides: { value: number; label: string }[];
    range?: { low: number; high: number } | null;
    /** Points since the previous run, and its date. */
    moved?: { points: number; since: string | null } | null;
    /** Whether a rise is good news (readiness, forecast) or bad (risk). */
    upIsGood: boolean;
    children?: ReactNode;
}) {
    const showMove = moved && Math.round(Math.abs(moved.points)) >= 1;
    const good = moved ? moved.points > 0 === upIsGood : false;

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
                <div className="flex items-baseline gap-2">
                    <span
                        className={cn(
                            'text-5xl leading-none font-semibold tracking-tight tabular-nums',
                            tone,
                        )}
                    >
                        {Math.round(value)}
                    </span>
                    <span className="text-sm text-muted-foreground">
                        {unit}
                    </span>
                </div>
                <div className="flex items-center gap-2">
                    {showMove && (
                        <span
                            className="inline-flex items-center gap-0.5 rounded-full border border-sidebar-border/70 px-2 py-0.5 text-[11px] font-medium tabular-nums dark:border-sidebar-border"
                            title={`Since the run of ${shortDate(moved.since)}`}
                        >
                            {moved.points > 0 ? (
                                <ArrowUpRight
                                    className={cn(
                                        'size-3.5',
                                        good
                                            ? 'text-teal-600'
                                            : 'text-rose-600 dark:text-rose-500',
                                    )}
                                />
                            ) : (
                                <ArrowDownRight
                                    className={cn(
                                        'size-3.5',
                                        good
                                            ? 'text-teal-600'
                                            : 'text-rose-600 dark:text-rose-500',
                                    )}
                                />
                            )}
                            {Math.round(Math.abs(moved.points))} since{' '}
                            {shortDate(moved.since)}
                        </span>
                    )}
                    {badge}
                </div>
            </div>
            <div className="relative h-2 rounded-full bg-muted">
                {range && (
                    <span
                        className="absolute inset-y-0 rounded-full bg-foreground/10"
                        style={{
                            left: `${range.low}%`,
                            width: `${range.high - range.low}%`,
                        }}
                    />
                )}
                <span
                    className={cn(
                        'absolute inset-y-0 left-0 rounded-full',
                        fill,
                    )}
                    style={{ width: `${Math.max(1, value)}%` }}
                />
                {guides.map((g) => (
                    <span
                        key={g.label}
                        aria-hidden
                        className="absolute -inset-y-0.5 w-px bg-card"
                        style={{ left: `${g.value}%` }}
                    />
                ))}
            </div>
            {children && (
                <div className="text-xs leading-relaxed text-muted-foreground">
                    {children}
                </div>
            )}
        </div>
    );
}
