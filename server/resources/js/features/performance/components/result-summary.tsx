import { cn } from '@/lib/utils';
import { bandTone, formatPercent, formatScore } from '../constants';
import type { RatingBand, ResultDisplay, ScoreResult } from '../types';
import { RatingLadder } from './rating-ladder';

type Props = {
    result: ScoreResult;
    bands: RatingBand[];
    display: ResultDisplay;
    /** Set while the appraisal is still being filled in. */
    live?: boolean;
};

/**
 * The result of an appraisal, led with in the way its framework asks for. One
 * company's headline is the band ("Exceeds Expectations"), another's is the
 * attainment ("78.4%"), another's is a point on a scale — so the headline is a
 * setting, and the other two readings stay beside it rather than disappearing.
 *
 * Below the headline sits the {@see RatingLadder}: the whole rating model, with
 * this result standing on it. A number nobody can place is not a result.
 */
export function ResultSummary({ result, bands, display, live = false }: Props) {
    const band = result.band;
    const tone = bandTone(band?.tone);
    const unscored = result.percent === null;

    const headline =
        display === 'percent'
            ? formatPercent(result.percent)
            : display === 'points'
              ? formatScore(result.normalized)
              : (band?.label ?? 'Not yet rated');

    const supporting =
        display === 'band'
            ? `${formatPercent(result.percent)} attainment`
            : display === 'percent'
              ? (band?.label ?? 'Not yet rated')
              : `${band?.label ?? 'Not yet rated'} · ${formatPercent(result.percent)}`;

    return (
        <div
            className={cn(
                'flex flex-col gap-4 rounded-xl border p-5',
                unscored
                    ? 'border-dashed border-border bg-card/50'
                    : cn(tone.border, tone.soft),
            )}
        >
            <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
                <div className="min-w-0">
                    <p className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                        {live ? 'Result so far' : 'Result'}
                        {display === 'points' && ' · out of 5.00'}
                    </p>
                    <p
                        className={cn(
                            'mt-0.5 text-3xl leading-tight font-semibold tracking-tight',
                            display !== 'band' && 'tabular-nums',
                            unscored ? 'text-muted-foreground' : tone.text,
                        )}
                    >
                        {headline}
                    </p>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {supporting}
                    </p>
                </div>

                <div className="text-right text-xs text-muted-foreground tabular-nums">
                    <p>
                        <span className="font-semibold text-foreground">
                            {result.scored}
                        </span>{' '}
                        of {result.total} criteria rated
                    </p>
                    {result.normalized !== null && display !== 'points' && (
                        <p className="mt-0.5">
                            {formatScore(result.normalized)} on the 1–5 index
                        </p>
                    )}
                </div>
            </div>

            <RatingLadder bands={bands} percent={result.percent} />

            {band?.description && (
                <p className={cn('text-xs leading-relaxed', tone.text)}>
                    {band.description}
                </p>
            )}
        </div>
    );
}
