import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { formatLineScore, scaleDescriptor, scaleFraction } from '../constants';
import type { ScaleLevel, ScaleType } from '../types';
import { ScaleInput } from './scale-input';

type Props = {
    label: string;
    weight: number;
    score: number | null;
    scaleType: ScaleType;
    scaleMin: number;
    scaleMax: number;
    scaleLevels: ScaleLevel[] | null;
    remarks: string | null;
    criterionActive: boolean | null;
    editable: boolean;
    onScoreChange: (value: number) => void;
    onRemarksChange: (value: string) => void;
};

/** Colour a normalised (0–1) line fraction, matching the overall score tones. */
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
 * One criterion line of the scorecard: its label, weight and rating scale, the
 * rating control (scale-aware when editable, otherwise the captured score in its
 * own scale) and an optional comment.
 */
export function ScoreRow({
    label,
    weight,
    score,
    scaleType,
    scaleMin,
    scaleMax,
    scaleLevels,
    remarks,
    criterionActive,
    editable,
    onScoreChange,
    onRemarksChange,
}: Props) {
    const line = {
        score,
        scale_type: scaleType,
        scale_min: scaleMin,
        scale_max: scaleMax,
        scale_levels: scaleLevels,
    };

    return (
        <div className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0 sm:pt-1">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm font-medium">{label}</p>
                    <span className="rounded-full border border-border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground tabular-nums">
                        {weight}%
                    </span>
                    <span className="rounded-full border border-border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                        {scaleDescriptor(line)}
                    </span>
                    {criterionActive === false && (
                        <span className="rounded-full border border-border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                            Archived
                        </span>
                    )}
                </div>
            </div>

            <div className="flex flex-col items-stretch gap-2 sm:w-[58%] sm:items-end">
                {editable ? (
                    <ScaleInput
                        scaleType={scaleType}
                        scaleMin={scaleMin}
                        scaleMax={scaleMax}
                        scaleLevels={scaleLevels}
                        value={score}
                        onChange={onScoreChange}
                    />
                ) : (
                    <div className="flex items-baseline gap-2">
                        <span
                            className={cn(
                                'text-lg font-semibold tabular-nums',
                                fractionTone(scaleFraction(line)),
                            )}
                        >
                            {formatLineScore(line)}
                        </span>
                        {score === null && (
                            <span className="text-xs text-muted-foreground">
                                not scored
                            </span>
                        )}
                    </div>
                )}

                {editable ? (
                    <Input
                        value={remarks ?? ''}
                        onChange={(e) => onRemarksChange(e.target.value)}
                        placeholder="Comment (optional)"
                        className="sm:w-full"
                    />
                ) : (
                    remarks && (
                        <p className="text-sm text-muted-foreground sm:text-right">
                            {remarks}
                        </p>
                    )
                )}
            </div>
        </div>
    );
}
