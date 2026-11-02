import { CircleAlert, Target } from 'lucide-react';
import { Skeleton } from '@/components/ui/skeleton';
import {
    BreakdownDialog,
    BreakdownSection,
} from '@/features/prediction-insights/components/breakdown-dialog';
import { ContextGate } from '@/features/prediction-insights/components/context-gate';
import { NextSteps } from '@/features/prediction-insights/components/next-steps';
import { OtherPredictions } from '@/features/prediction-insights/components/other-predictions';
import { PeerComparison } from '@/features/prediction-insights/components/peer-comparison';
import {
    AppraisalPanel,
    OffboardingNotice,
    RecordPanels,
} from '@/features/prediction-insights/components/record-panels';
import { ScoreHeadline } from '@/features/prediction-insights/components/score-headline';
import { ScoreTrend } from '@/features/prediction-insights/components/score-trend';
import { VerdictCard } from '@/features/prediction-insights/components/verdict-card';
import { peerGroups, sinceLastRun } from '@/features/prediction-insights/lib';
import type { PredictionContext } from '@/features/prediction-insights/types';
import { usePredictionContext } from '@/features/prediction-insights/use-prediction-context';
import type { PredictionContextState } from '@/features/prediction-insights/use-prediction-context';
import { forecastSteps, forecastVerdict, personalTrackRecord } from '../brief';
import {
    BAND_LABELS,
    BAND_PHRASES,
    confidenceLabel,
    formatConfidence,
    formatRange,
    ratingBarTone,
    ratingTone,
} from '../constants';
import { BAND_GROUPS, bandThresholds, forecastSummary } from '../explain';
import type { ForecastRun, ForecastScore } from '../types';
import { BandBadge } from './band-badge';
import { BandChances } from './band-chances';
import { RatingCurve } from './rating-curve';
import { TrajectoryChart } from './trajectory-chart';

const BAND_FILL = Object.fromEntries(
    BAND_GROUPS.map((g) => [g.key, g.svgFill]),
);

/**
 * One person's performance forecast as a decision brief (ADR 0099). Summary: the
 * forecast, its range and the chance of every band, the read once readiness and
 * flight risk are weighed in, next steps for the cycle, and where they stand among
 * peers. Reasons: the latest appraisal on the model's own curve, and that
 * appraisal by section. Track record: their forecasts against what each period
 * gave, and the rating trajectory. Record: the rest of the record the viewer may
 * see.
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
    const employee = score?.employee ?? null;
    const state = usePredictionContext('performance', employee?.id ?? null);
    const context = state.status === 'ready' ? state.context : null;

    return (
        <BreakdownDialog
            person={employee}
            description={`Performance forecast for ${employee?.full_name ?? 'this employee'}`}
            profileUrl={context?.employment.profile_url}
            onOpenChange={onOpenChange}
            tabs={
                score && employee
                    ? [
                          {
                              key: 'summary',
                              label: 'Summary',
                              content: (
                                  <Summary
                                      score={score}
                                      run={run}
                                      state={state}
                                      actual={actual}
                                      periodName={periodName}
                                  />
                              ),
                          },
                          {
                              key: 'reasons',
                              label: 'Reasons',
                              content: (
                                  <Reasons
                                      score={score}
                                      run={run}
                                      state={state}
                                  />
                              ),
                          },
                          {
                              key: 'track',
                              label: 'Track record',
                              content: (
                                  <TrackRecord
                                      score={score}
                                      run={run}
                                      state={state}
                                      actual={actual}
                                  />
                              ),
                          },
                          {
                              key: 'record',
                              label: 'Record',
                              content: (
                                  <ContextGate state={state} lines={4}>
                                      {(ctx) => (
                                          <RecordPanels
                                              context={ctx}
                                              withoutAppraisals
                                          />
                                      )}
                                  </ContextGate>
                              ),
                          },
                      ]
                    : []
            }
        />
    );
}

function rangeOf(score: ForecastScore) {
    return score.predicted_low !== null && score.predicted_high !== null
        ? { low: score.predicted_low, high: score.predicted_high }
        : null;
}

function Summary({
    score,
    run,
    state,
    actual,
    periodName,
}: {
    score: ForecastScore;
    run: ForecastRun;
    state: PredictionContextState;
    actual: number | null;
    periodName: string | null;
}) {
    const employee = score.employee!;
    const range = rangeOf(score);
    const settled = state.status !== 'loading';
    const context = state.context;
    const steps = settled
        ? forecastSteps(score, run, context, employee.id)
        : [];

    return (
        <>
            <ScoreHeadline
                value={score.predicted_rating}
                unit={
                    <>
                        / 100 forecast
                        {range &&
                            ` · likely ${formatRange(range.low, range.high)}`}
                    </>
                }
                tone={ratingTone(score.predicted_rating)}
                fill={ratingBarTone(score.predicted_rating)}
                badge={<BandBadge band={score.band} />}
                guides={bandThresholds(run)}
                range={range}
                moved={context ? sinceLastRun(context.history) : null}
                upIsGood
            >
                <span className="font-medium text-foreground">
                    {confidenceLabel(score.confidence)}
                </span>{' '}
                — a {formatConfidence(score.confidence)} chance the next
                appraisal lands {BAND_PHRASES[score.band]}
                {range
                    ? `, and four in five land within ${formatRange(range.low, range.high)}.`
                    : '.'}
            </ScoreHeadline>

            {score.explanation && (
                <BandChances
                    chances={score.explanation.chances}
                    band={score.band}
                />
            )}

            {actual !== null && (
                <ActualCard
                    score={score}
                    actual={actual}
                    periodName={periodName}
                />
            )}

            {context?.offboarding && (
                <OffboardingNotice offboarding={context.offboarding} />
            )}

            {settled ? (
                <VerdictCard verdict={forecastVerdict(score, run, context)} />
            ) : (
                <Skeleton className="h-28 w-full rounded-xl" />
            )}

            {(!settled || steps.length > 0) && (
                <BreakdownSection title="Suggested next steps">
                    {settled ? (
                        <NextSteps steps={steps} />
                    ) : (
                        <Skeleton className="h-32 w-full rounded-xl" />
                    )}
                </BreakdownSection>
            )}

            {context && Object.keys(context.signals).length > 0 && (
                <BreakdownSection
                    title="Their other predictions"
                    description="A strong forecast is a promotion question when readiness is high, and a retention one when flight risk is."
                >
                    <OtherPredictions
                        signals={context.signals}
                        employeeId={employee.id}
                    />
                </BreakdownSection>
            )}

            <BreakdownSection
                title="Compared with"
                description="Everyone's forecast in this run, their department and their position."
            >
                <PeerComparison
                    groups={peerGroups(
                        run.forecasts.map((f) => ({
                            score: f.predicted_rating,
                            employee: f.employee,
                        })),
                        employee,
                    )}
                    value={score.predicted_rating}
                    name={employee.full_name}
                    noun="forecast"
                />
            </BreakdownSection>
        </>
    );
}

function ActualCard({
    score,
    actual,
    periodName,
}: {
    score: ForecastScore;
    actual: number;
    periodName: string | null;
}) {
    const range = rangeOf(score);

    return (
        <div className="flex items-start gap-3 rounded-xl border border-sidebar-border/60 px-4 py-3 dark:border-sidebar-border">
            <Target className="mt-0.5 size-4 shrink-0 text-[#0ABFBF]" />
            <p className="text-sm">
                <span className="font-medium">Actual: {actual.toFixed(1)}</span>
                {periodName ? ` for ${periodName}` : ''} —{' '}
                {range && actual >= range.low && actual <= range.high
                    ? 'inside the forecast range'
                    : range
                      ? 'outside the forecast range'
                      : 'no range was forecast'}
                , {Math.abs(actual - score.predicted_rating).toFixed(1)} points
                from the forecast.
            </p>
        </div>
    );
}

function Reasons({
    score,
    run,
    state,
}: {
    score: ForecastScore;
    run: ForecastRun;
    state: PredictionContextState;
}) {
    const latest = score.history.at(-1) ?? null;

    return (
        <>
            {run.reference && score.features.rating_latest !== undefined && (
                <BreakdownSection
                    title={`Why ${Math.round(score.predicted_rating)}`}
                    description={forecastSummary(score, run.scored_by)}
                >
                    <RatingCurve
                        reference={run.reference}
                        latest={score.features.rating_latest}
                        forecast={score.predicted_rating}
                    />
                </BreakdownSection>
            )}

            <BreakdownSection
                title="What the latest appraisal was made of"
                description="The model reads only the overall result; its sections show what carried it, and where coaching would count."
            >
                <ContextGate state={state} lines={2}>
                    {(ctx) =>
                        ctx.appraisals === null ? (
                            <p className="text-sm text-muted-foreground">
                                Appraisal details need access to Performance.
                            </p>
                        ) : (
                            <AppraisalPanel appraisals={ctx.appraisals} />
                        )
                    }
                </ContextGate>
            </BreakdownSection>

            <BreakdownSection title="What it was read from">
                <div className="flex flex-col gap-0.5 rounded-lg border border-sidebar-border/60 bg-card/40 px-3 py-2 dark:border-sidebar-border">
                    <span className="text-[11px] text-muted-foreground">
                        Latest completed appraisal before the forecast period
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
                <p className="text-xs text-muted-foreground">
                    {run.scored_by === 'own'
                        ? 'Learned from how ratings in your organisation have moved from one cycle to the next: the latest appraisal is the one record it reads. The range is how far next ratings here actually strayed from forecasts like this one.'
                        : 'Across a large reference workforce, the latest appraisal is the one record that predicts the next — earlier ratings, tenure and training add nothing once it is known. The range is how far next ratings actually strayed from forecasts like this one.'}{' '}
                    Drafts and the forecast period's own appraisal are never
                    read, and no demographic attribute is used.
                </p>
            </BreakdownSection>
        </>
    );
}

function TrackRecord({
    score,
    run,
    state,
    actual,
}: {
    score: ForecastScore;
    run: ForecastRun;
    state: PredictionContextState;
    actual: number | null;
}) {
    return (
        <>
            <BreakdownSection
                title="Their forecasts against what each period gave"
                description="Each run's forecast for them, its likely range, and the actual appraisal once the period was completed."
            >
                <ContextGate state={state} lines={3}>
                    {(ctx: PredictionContext) => {
                        const record = personalTrackRecord(ctx);

                        return (
                            <>
                                <ScoreTrend
                                    points={ctx.history}
                                    guides={bandThresholds(run)}
                                    tierFill={(band) =>
                                        BAND_FILL[band] ??
                                        'fill-muted-foreground'
                                    }
                                    tierLabels={BAND_LABELS}
                                    noun="Forecast"
                                    forecast
                                />
                                {record && (
                                    <p className="text-sm">
                                        <span className="font-medium">
                                            {record.inRange} of {record.checked}
                                        </span>{' '}
                                        earlier{' '}
                                        {record.checked === 1
                                            ? 'forecast'
                                            : 'forecasts'}{' '}
                                        landed in range,{' '}
                                        {record.meanMiss.toFixed(1)} points off
                                        on average.
                                    </p>
                                )}
                            </>
                        );
                    }}
                </ContextGate>
            </BreakdownSection>

            <BreakdownSection
                title="Rating trajectory"
                description="Their completed appraisals, oldest first, and where the next one is forecast."
            >
                <TrajectoryChart
                    history={score.history}
                    forecast={score.predicted_rating}
                    range={rangeOf(score)}
                    actual={actual}
                />
            </BreakdownSection>
        </>
    );
}
