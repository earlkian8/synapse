import { cn } from '@/lib/utils';
import { FIT_BAND_BARS, FIT_BAND_LABELS, FIT_BAND_STYLES } from '../constants';
import type { Fit, FitRank } from '../types';

/**
 * A compact fit pill (score + band colour) for table rows and cards. The
 * automatic ranking score the ApplicantScorer assigns — higher means a stronger
 * on-paper match for the role.
 */
export function FitBadge({
    fit,
    rank,
    className,
}: {
    fit: Fit | null;
    rank?: FitRank | null;
    className?: string;
}) {
    if (!fit) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    return (
        <span className={cn('inline-flex items-center gap-1.5', className)}>
            <span
                className={cn(
                    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold tabular-nums',
                    FIT_BAND_STYLES[fit.band],
                )}
                title={FIT_BAND_LABELS[fit.band]}
            >
                {fit.value}
                <span className="text-[10px] font-normal opacity-70">fit</span>
            </span>
            {rank && rank.position <= 3 && (
                <span className="text-[10px] font-medium text-muted-foreground">
                    #{rank.position}
                </span>
            )}
        </span>
    );
}

/**
 * The fit score as a labelled meter with its component breakdown — the expanded
 * form shown in the candidate detail drawer.
 */
export function FitMeter({ fit }: { fit: Fit }) {
    return (
        <div className="space-y-3">
            <div className="flex items-end justify-between">
                <div>
                    <span className="text-3xl font-semibold tabular-nums">
                        {fit.value}
                    </span>
                    <span className="ml-1 text-sm text-muted-foreground">
                        / 100
                    </span>
                </div>
                <span
                    className={cn(
                        'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium',
                        FIT_BAND_STYLES[fit.band],
                    )}
                >
                    {FIT_BAND_LABELS[fit.band]}
                </span>
            </div>

            <div className="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    className={cn(
                        'h-full rounded-full transition-all',
                        FIT_BAND_BARS[fit.band],
                    )}
                    style={{ width: `${fit.value}%` }}
                />
            </div>

            <ul className="space-y-1.5">
                {fit.breakdown.map((item) => (
                    <li
                        key={item.key}
                        className="flex items-center justify-between gap-3 text-sm"
                    >
                        <span className="flex min-w-0 items-center gap-2">
                            <span className="truncate text-muted-foreground">
                                {item.label}
                            </span>
                            <span className="truncate text-xs text-muted-foreground/70">
                                {item.detail}
                            </span>
                        </span>
                        <span className="shrink-0 font-medium tabular-nums">
                            {item.points}
                            <span className="text-muted-foreground">
                                /{item.max}
                            </span>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
