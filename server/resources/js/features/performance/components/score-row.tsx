import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { formatLineScore, scaleFraction } from '../constants';
import type { PerformanceScore } from '../types';
import { RatingControl } from './rating-control';

type Props = {
    line: PerformanceScore;
    /** The line's share of its section, as a percentage of the section total. */
    share: number | null;
    editable: boolean;
    onScoreChange: (value: number) => void;
    onRemarksChange: (value: string) => void;
};

/** Colour a normalised (0–1) line result, matching the band palette's ordering. */
function fractionTone(fraction: number | null): string {
    if (fraction === null) {
        return 'text-muted-foreground';
    }

    if (fraction >= 0.75) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (fraction >= 0.5) {
        return 'text-[#0a8b91] dark:text-[#0ABFBF]';
    }

    if (fraction >= 0.25) {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-rose-600 dark:text-rose-400';
}

/**
 * One criterion of the scorecard: what is being measured and what it counts for,
 * the rating control shaped by its own scale, and the evaluator's comment.
 *
 * The weight is shown as its **share of the section**, not as a bare number —
 * "24% of Capability" is a sentence an evaluator can act on; "35" is not.
 */
export function ScoreRow({
    line,
    share,
    editable,
    onScoreChange,
    onRemarksChange,
}: Props) {
    const fraction = scaleFraction(line);

    return (
        <div className="grid gap-3 px-4 py-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,20rem)] sm:gap-6">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <p className="text-sm font-medium">{line.label}</p>
                    {line.criterion_active === false && (
                        <span className="rounded-full border border-border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                            Archived criterion
                        </span>
                    )}
                </div>

                {line.description && (
                    <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                        {line.description}
                    </p>
                )}

                <p className="mt-1.5 text-[11px] text-muted-foreground/80">
                    {share === null
                        ? 'Unweighted'
                        : `${share.toFixed(0)}% of this section`}
                    {' · rated on '}
                    <span className="font-medium text-muted-foreground">
                        {line.scale_name ?? line.scale_descriptor}
                    </span>
                </p>
            </div>

            <div className="flex flex-col gap-2.5">
                {editable ? (
                    <RatingControl line={line} onChange={onScoreChange} />
                ) : (
                    <div className="flex items-baseline gap-2">
                        <span
                            className={cn(
                                'text-lg font-semibold',
                                fractionTone(fraction),
                            )}
                        >
                            {formatLineScore(line)}
                        </span>
                        {line.score === null && (
                            <span className="text-xs text-muted-foreground">
                                not rated
                            </span>
                        )}
                    </div>
                )}

                {editable ? (
                    <Input
                        value={line.remarks ?? ''}
                        onChange={(event) =>
                            onRemarksChange(event.target.value)
                        }
                        placeholder="Evidence for this rating (optional)"
                        aria-label={`Comment on ${line.label}`}
                        className="h-9"
                    />
                ) : (
                    line.remarks && (
                        <p className="text-sm leading-relaxed text-muted-foreground">
                            {line.remarks}
                        </p>
                    )
                )}
            </div>
        </div>
    );
}
