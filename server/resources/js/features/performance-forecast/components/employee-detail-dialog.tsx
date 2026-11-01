import { CircleAlert, Target } from 'lucide-react';
import { PersonAvatar } from '@/components/person-avatar';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { WorkforcePosition } from '@/features/prediction-insights/components/workforce-position';
import { focusDialogItself } from '@/features/prediction-insights/lib';
import { cn } from '@/lib/utils';
import {
    BAND_PHRASES,
    confidenceLabel,
    formatConfidence,
    formatRange,
    formatRating,
    ratingBarTone,
    ratingTone,
} from '../constants';
import { bandThresholds, forecastSummary } from '../explain';
import type { ForecastRun, ForecastScore } from '../types';
import { BandBadge } from './band-badge';
import { BandChances } from './band-chances';
import { RatingCurve } from './rating-curve';
import { TrajectoryChart } from './trajectory-chart';

/**
 * A drill-down on one employee's forecast: the rating, the range it is likely to
 * land in, the chance of every band, why — the latest appraisal on the model's own
 * curve — the trajectory behind it, and once the period is appraised how it turned
 * out.
 */
export function EmployeeDetailDialog({
    score,
    run,
    actual,
    periodName,
    onOpenChange,
}: {
    score: ForecastScore | null;
    run: ForecastRun;
    /** The completed appraisal for the forecast period, when there is one. */
    actual: number | null;
    periodName: string | null;
    onOpenChange: (open: boolean) => void;
}) {
    // Whose model made the forecast: the organisation's own, or the general one.
    const scoredBy = run.scored_by;
    const range =
        score && score.predicted_low !== null && score.predicted_high !== null
            ? { low: score.predicted_low, high: score.predicted_high }
            : null;
    const latest = score?.history.at(-1) ?? null;

    return (
        <Dialog open={score !== null} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
                onOpenAutoFocus={focusDialogItself}
            >
                {score && score.employee && (
                    <>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-3">
                                <PersonAvatar
                                    name={score.employee.full_name}
                                    initials={score.employee.initials}
                                    photo={score.employee.photo}
                                    className="size-10"
                                />
                                <span className="flex min-w-0 flex-col">
                                    <span className="truncate text-base">
                                        {score.employee.full_name}
                                    </span>
                                    <span className="truncate text-xs font-normal text-muted-foreground">
                                        {score.employee.position ??
                                            score.employee.employee_no}
                                        {score.employee.department
                                            ? ` · ${score.employee.department}`
                                            : ''}
                                    </span>
                                </span>
                            </DialogTitle>
                            <DialogDescription className="sr-only">
                                Performance forecast for{' '}
                                {score.employee.full_name}
                            </DialogDescription>
                        </DialogHeader>

                        {/* Rating headline */}
                        <div className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card/60 p-4 dark:border-sidebar-border">
                            <div className="flex items-end justify-between">
                                <div className="flex items-baseline gap-1.5">
                                    <span
                                        className={cn(
                                            'text-4xl font-semibold tracking-tight',
                                            ratingTone(score.predicted_rating),
                                        )}
                                    >
                                        {formatRating(score.predicted_rating)}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        / 100 forecast
                                        {range &&
                                            ` · likely ${formatRange(range.low, range.high)}`}
                                    </span>
                                </div>
                                <BandBadge band={score.band} />
                            </div>
                            <div className="relative h-2 overflow-hidden rounded-full bg-muted">
                                {range && (
                                    <div
                                        className="absolute inset-y-0 rounded-full bg-foreground/10"
                                        style={{
                                            left: `${range.low}%`,
                                            width: `${range.high - range.low}%`,
                                        }}
                                    />
                                )}
                                <div
                                    className={cn(
                                        'relative h-full rounded-full',
                                        ratingBarTone(score.predicted_rating),
                                    )}
                                    style={{
                                        width: `${score.predicted_rating}%`,
                                    }}
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                <span className="font-medium text-foreground">
                                    {confidenceLabel(score.confidence)}
                                </span>{' '}
                                — a {formatConfidence(score.confidence)} chance
                                the next appraisal lands{' '}
                                {BAND_PHRASES[score.band]}
                                {range
                                    ? `, and four in five land within ${formatRange(range.low, range.high)}.`
                                    : '.'}
                            </p>
                            {score.explanation && (
                                <BandChances
                                    chances={score.explanation.chances}
                                    band={score.band}
                                />
                            )}
                            <WorkforcePosition
                                scores={run.forecasts.map(
                                    (f) => f.predicted_rating,
                                )}
                                value={score.predicted_rating}
                                name={score.employee.full_name}
                                noun="forecast"
                                thresholds={bandThresholds(run)}
                            />
                        </div>

                        {/* Why */}
                        {run.reference &&
                            score.features.rating_latest !== undefined && (
                                <section className="flex flex-col gap-2">
                                    <div className="flex flex-col gap-1">
                                        <h3 className="text-sm font-semibold">
                                            Why{' '}
                                            {formatRating(
                                                score.predicted_rating,
                                            )}
                                        </h3>
                                        <p className="text-sm text-muted-foreground">
                                            {forecastSummary(score, scoredBy)}
                                        </p>
                                    </div>
                                    <RatingCurve
                                        reference={run.reference}
                                        latest={score.features.rating_latest}
                                        forecast={score.predicted_rating}
                                    />
                                </section>
                            )}

                        {/* How it turned out */}
                        {actual !== null && (
                            <div className="flex items-start gap-3 rounded-xl border border-sidebar-border/60 bg-card/40 px-4 py-3 dark:border-sidebar-border">
                                <Target className="mt-0.5 size-4 shrink-0 text-[#0ABFBF]" />
                                <p className="text-sm">
                                    <span className="font-medium">
                                        Actual: {actual.toFixed(1)}
                                    </span>
                                    {periodName ? ` for ${periodName}` : ''} —{' '}
                                    {range &&
                                    actual >= range.low &&
                                    actual <= range.high
                                        ? 'inside the forecast range'
                                        : range
                                          ? 'outside the forecast range'
                                          : 'no range was forecast'}
                                    ,{' '}
                                    {Math.abs(
                                        actual - score.predicted_rating,
                                    ).toFixed(1)}{' '}
                                    points from the forecast.
                                </p>
                            </div>
                        )}

                        {/* Trajectory */}
                        <div className="flex flex-col gap-1">
                            <h3 className="text-sm font-semibold">
                                Rating trajectory
                            </h3>
                            <TrajectoryChart
                                history={score.history}
                                forecast={score.predicted_rating}
                                range={range}
                                actual={actual}
                            />
                        </div>

                        {/* What it is based on */}
                        <div className="flex flex-col gap-2">
                            <h3 className="text-sm font-semibold">
                                What it was read from
                            </h3>
                            <div className="flex flex-col gap-0.5 rounded-lg border border-sidebar-border/60 bg-card/40 px-3 py-2 dark:border-sidebar-border">
                                <span className="text-[11px] text-muted-foreground">
                                    Latest completed appraisal before the
                                    forecast period
                                </span>
                                <span className="text-sm font-medium tabular-nums">
                                    {score.features.rating_latest !== undefined
                                        ? `${score.features.rating_latest.toFixed(1)}%`
                                        : '—'}
                                    {latest?.label && (
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            · {latest.label}
                                        </span>
                                    )}
                                </span>
                            </div>
                            {score.warnings.length > 0 && (
                                <ul className="flex flex-col gap-1 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-300">
                                    {score.warnings.map((warning) => (
                                        <li
                                            key={warning}
                                            className="flex items-start gap-1.5"
                                        >
                                            <CircleAlert className="mt-0.5 size-3 shrink-0" />
                                            {warning}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <p className="mt-1 text-xs text-muted-foreground">
                                {scoredBy === 'own'
                                    ? 'Learned from how ratings in your organisation have moved from one cycle to the next: the latest appraisal is the one record it reads. The range is how far next ratings here actually strayed from forecasts like this one.'
                                    : 'Across a large reference workforce, the latest appraisal is the one record that predicts the next — earlier ratings, tenure and training add nothing once it is known. The range is how far next ratings actually strayed from forecasts like this one.'}{' '}
                                Drafts and the forecast period's own appraisal
                                are never read, and no demographic attribute is
                                used.
                            </p>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
