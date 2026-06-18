import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { RATING_LABELS, formatScore, scoreTone } from '../constants';
import { RatingInput } from './rating-input';

type Props = {
    label: string;
    weight: number;
    score: number | null;
    remarks: string | null;
    criterionActive: boolean | null;
    editable: boolean;
    onScoreChange: (value: number) => void;
    onRemarksChange: (value: string) => void;
};

/**
 * One criterion line of the scorecard: its label + weight, the rating (a 1–5
 * selector when editable, otherwise the captured score and what it means) and an
 * optional comment.
 */
export function ScoreRow({
    label,
    weight,
    score,
    remarks,
    criterionActive,
    editable,
    onScoreChange,
    onRemarksChange,
}: Props) {
    return (
        <div className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0 sm:pt-1">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm font-medium">{label}</p>
                    <span className="rounded-full border border-border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground tabular-nums">
                        {weight}%
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
                    <RatingInput value={score} onChange={onScoreChange} />
                ) : (
                    <div className="flex items-baseline gap-2">
                        <span
                            className={cn(
                                'text-lg font-semibold tabular-nums',
                                scoreTone(score),
                            )}
                        >
                            {formatScore(score)}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {score === null
                                ? 'not scored'
                                : (RATING_LABELS[Math.round(score)] ?? '')}
                        </span>
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
