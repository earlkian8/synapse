import { CircleAlert, Layers } from 'lucide-react';
import { Skeleton } from '@/components/ui/skeleton';
import {
    BreakdownDialog,
    BreakdownSection,
} from '@/features/prediction-insights/components/breakdown-dialog';
import { ContextGate } from '@/features/prediction-insights/components/context-gate';
import { ContributionLedger } from '@/features/prediction-insights/components/contribution-ledger';
import { NextSteps } from '@/features/prediction-insights/components/next-steps';
import { OtherPredictions } from '@/features/prediction-insights/components/other-predictions';
import { PeerComparison } from '@/features/prediction-insights/components/peer-comparison';
import {
    OffboardingNotice,
    RecordPanels,
} from '@/features/prediction-insights/components/record-panels';
import { ScoreHeadline } from '@/features/prediction-insights/components/score-headline';
import { ScoreTrend } from '@/features/prediction-insights/components/score-trend';
import { VerdictCard } from '@/features/prediction-insights/components/verdict-card';
import {
    chanceWords,
    peerGroups,
    sinceLastRun,
} from '@/features/prediction-insights/lib';
import type { PredictionContext } from '@/features/prediction-insights/types';
import { usePredictionContext } from '@/features/prediction-insights/use-prediction-context';
import type { PredictionContextState } from '@/features/prediction-insights/use-prediction-context';
import { cn } from '@/lib/utils';
import {
    averageRate,
    forecastChance,
    nextTarget,
    promotionSteps,
    promotionVerdict,
} from '../brief';
import {
    BASIS_LABELS,
    BASIS_NOTES,
    formatChange,
    formatLift,
    formatProbability,
    TIER_DESCRIPTIONS,
    TIER_LABELS,
    tierBarTone,
    tierTone,
} from '../constants';
import {
    ledgerSteps,
    READINESS_GROUPS,
    readinessSummary,
    readinessThresholds,
    tierOfScore,
    typicalRecord,
} from '../explain';
import type { ReadinessRun, ReadinessScore } from '../types';
import { AppraisalMap } from './appraisal-map';
import { FactorList } from './factor-list';
import { NextAppraisalChart } from './next-appraisal-chart';
import { TierBadge } from './tier-badge';

const TIER_FILL = Object.fromEntries(
    READINESS_GROUPS.map((g) => [g.key, g.svgFill]),
);

/**
 * One person's promotion readiness as a decision brief (ADR 0099). Summary: the
 * score and its odds, the read once flight risk and the forecast are weighed in,
 * next steps, and where they stand among peers. Reasons: the walk from a typical
 * record and the appraisal map with them on it. Next appraisal: readiness at every
 * next rating, with the forecast's likely range on it. Record: their trend and
 * appraisals by section, and the rest of the record the viewer may see.
 */
export function EmployeeDetailDialog({
    score,
    run,
    onOpenChange,
    onSelect,
}: {
    score: ReadinessScore | null;
    run: ReadinessRun;
    onOpenChange: (open: boolean) => void;
    /** Open another person's breakdown (from the appraisal map). */
    onSelect?: (score: ReadinessScore) => void;
}) {
    const employee = score?.employee ?? null;
    const state = usePredictionContext('promotion', employee?.id ?? null);
    const context = state.status === 'ready' ? state.context : null;

    return (
        <BreakdownDialog
            person={employee}
            description={`Promotion-readiness breakdown for ${employee?.full_name ?? 'this employee'}`}
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
                                      onSelect={onSelect}
                                  />
                              ),
                          },
                          {
                              key: 'next',
                              label: 'Next appraisal',
                              content: (
                                  <NextAppraisal
                                      score={score}
                                      run={run}
                                      context={context}
                                  />
                              ),
                          },
                          {
                              key: 'record',
                              label: 'Record',
                              content: (
                                  <ContextGate state={state} lines={4}>
                                      {(ctx) => (
                                          <Record run={run} context={ctx} />
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

function Summary({
    score,
    run,
    state,
}: {
    score: ReadinessScore;
    run: ReadinessRun;
    state: PredictionContextState;
}) {
    const employee = score.employee!;
    const own = run.scored_by === 'own' && run.base_rate !== null;
    const average = averageRate(run);
    const settled = state.status !== 'loading';
    const context = state.context;

    return (
        <>
            <ScoreHeadline
                value={score.score}
                unit="/ 100 readiness"
                tone={tierTone(score.tier)}
                fill={tierBarTone(score.tier)}
                badge={<TierBadge tier={score.tier} />}
                guides={readinessThresholds(run)}
                moved={context ? sinceLastRun(context.history) : null}
                upIsGood
            >
                {TIER_DESCRIPTIONS[score.tier]}. Of people with this record{' '}
                {own
                    ? 'in your organisation’s own history'
                    : 'in the reference workforce'}
                ,{' '}
                <span className="font-medium text-foreground">
                    {formatProbability(score.probability)}
                </span>{' '}
                {own
                    ? 'were promoted before their next appraisal, or within a year'
                    : 'were promoted within a year'}{' '}
                — {formatLift(score.probability, average)} the average of{' '}
                {formatProbability(average)}.
            </ScoreHeadline>

            {context?.offboarding && (
                <OffboardingNotice offboarding={context.offboarding} />
            )}

            {settled ? (
                <VerdictCard verdict={promotionVerdict(score, run, context)} />
            ) : (
                <Skeleton className="h-28 w-full rounded-xl" />
            )}

            {(!settled ||
                promotionSteps(score, run, context, employee.id).length >
                    0) && (
                <BreakdownSection title="Suggested next steps">
                    {settled ? (
                        <NextSteps
                            steps={promotionSteps(
                                score,
                                run,
                                context,
                                employee.id,
                            )}
                        />
                    ) : (
                        <Skeleton className="h-32 w-full rounded-xl" />
                    )}
                </BreakdownSection>
            )}

            {context && Object.keys(context.signals).length > 0 && (
                <BreakdownSection
                    title="Their other predictions"
                    description="A promotion case is stronger when the next appraisal looks good too, and more urgent when the person may leave."
                >
                    <OtherPredictions
                        signals={context.signals}
                        employeeId={employee.id}
                    />
                </BreakdownSection>
            )}

            <BreakdownSection
                title="Compared with"
                description="Everyone's readiness in this run, their department and their position."
            >
                <PeerComparison
                    groups={peerGroups(run.scores, employee)}
                    value={score.score}
                    name={employee.full_name}
                    noun="readiness"
                />
            </BreakdownSection>
        </>
    );
}

function Reasons({
    score,
    run,
    onSelect,
}: {
    score: ReadinessScore;
    run: ReadinessRun;
    onSelect?: (score: ReadinessScore) => void;
}) {
    const employee = score.employee!;
    const own = run.scored_by === 'own' && run.base_rate !== null;

    return (
        <>
            {score.basis && (
                <div className="flex items-start gap-3 rounded-xl border border-sidebar-border/60 px-4 py-3 dark:border-sidebar-border">
                    <Layers
                        className={cn(
                            'mt-0.5 size-4 shrink-0',
                            score.basis === 'two_appraisals'
                                ? 'text-[#0ABFBF]'
                                : 'text-amber-500',
                        )}
                    />
                    <div className="flex flex-col gap-0.5">
                        <span className="text-sm font-medium">
                            Based on {BASIS_LABELS[score.basis].toLowerCase()}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {BASIS_NOTES[score.basis]}
                        </span>
                    </div>
                </div>
            )}

            {score.explanation ? (
                <BreakdownSection
                    title={`Why ${Math.round(score.score)}`}
                    description={readinessSummary(score)}
                >
                    <ContributionLedger
                        label={`How ${employee.full_name}'s readiness adds up from a typical record's`}
                        start={{
                            label: 'A typical record',
                            detail: typicalRecord(score, run),
                            value: score.explanation.baseline,
                        }}
                        steps={ledgerSteps(score)}
                        end={{
                            label: employee.full_name,
                            value: score.score,
                            fill: tierBarTone(score.tier),
                        }}
                        guides={readinessThresholds(run)}
                    />
                    <p className="text-xs text-muted-foreground">
                        Readiness points each input adds to or takes from a
                        typical record with the same history, shared fairly
                        where the inputs work together, so they add up to this
                        score.
                    </p>
                </BreakdownSection>
            ) : (
                score.factors.length > 0 && (
                    <BreakdownSection
                        title="What moves this score"
                        description="Readiness points each input adds or takes away, compared with a typical record. Run a new assessment for the full breakdown."
                    >
                        <FactorList factors={score.factors} />
                    </BreakdownSection>
                )
            )}

            <BreakdownSection
                title="On the appraisal map"
                description="Everyone in this run by latest appraisal and how much it changed. Readiness rises to the upper right; select a dot to open that person."
            >
                <AppraisalMap
                    scores={run.scores}
                    reference={run.reference}
                    highlight={score.id}
                    onOpen={(other) => onSelect?.(other)}
                />
            </BreakdownSection>

            <BreakdownSection title="What it was read from">
                <AppraisalGrid score={score} />
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
                    A readiness score is a prompt for a conversation, not a
                    decision. It compares this appraisal record with those of
                    people who were promoted{' '}
                    {own
                        ? 'in your organisation'
                        : 'in a general reference workforce'}{' '}
                    — it cannot see skills, role openings or anything else a
                    promotion case weighs. Department, pay and every demographic
                    attribute are left out.
                </p>
            </BreakdownSection>
        </>
    );
}

function NextAppraisal({
    score,
    run,
    context,
}: {
    score: ReadinessScore;
    run: ReadinessRun;
    context: PredictionContext | null;
}) {
    const outlook = score.explanation?.outlook;
    const latest = score.features.rating_latest;
    const forecast = context?.signals.performance ?? null;
    const likely =
        forecast && forecast.low != null && forecast.high != null
            ? {
                  forecast: forecast.score,
                  low: forecast.low,
                  high: forecast.high,
              }
            : null;
    const target = nextTarget(score);
    const chance = forecastChance(forecast, target?.from ?? null);

    if (!outlook || latest === undefined) {
        return (
            <p className="rounded-xl border border-dashed border-sidebar-border/70 px-4 py-5 text-sm text-muted-foreground dark:border-sidebar-border">
                This run is from before the next appraisal was mapped. Run a new
                assessment to see what the next appraisal would do.
            </p>
        );
    }

    return (
        <>
            <BreakdownSection
                title="Readiness after the next appraisal"
                description={outlookSummary(outlook, latest, score.score, (v) =>
                    tierOfScore(run, v),
                )}
            >
                <NextAppraisalChart
                    outlook={outlook}
                    latest={latest}
                    tierScores={run.reference?.tier_scores ?? null}
                    tierOf={(v) => tierOfScore(run, v)}
                    likely={likely}
                />
                <p className="text-xs text-muted-foreground">
                    The next rating becomes the latest, and its change on
                    today’s {latest.toFixed(1)}% becomes the change — read by
                    the model exactly as it will be then.
                    {likely
                        ? ' The shaded band is where the performance forecast expects the next appraisal to land.'
                        : ''}
                </p>
            </BreakdownSection>

            {likely && target && chance !== null && (
                <BreakdownSection title="If the forecast holds">
                    <p className="text-sm">
                        The performance forecast puts their next appraisal at{' '}
                        <span className="font-medium">
                            {Math.round(likely.forecast)}%
                        </span>{' '}
                        (likely {Math.round(likely.low)}–
                        {Math.round(likely.high)}
                        %). Reaching {target.from}%, where{' '}
                        {TIER_LABELS[target.tier]} starts, is{' '}
                        <span className="font-medium">
                            {chanceWords(chance)}
                        </span>
                        .
                    </p>
                    <p className="text-xs text-muted-foreground">
                        This joins two models — the forecast’s range and this
                        curve — so treat it as a rough guide, not a third
                        prediction.
                    </p>
                </BreakdownSection>
            )}
        </>
    );
}

function Record({
    run,
    context,
}: {
    run: ReadinessRun;
    context: PredictionContext;
}) {
    return (
        <>
            <BreakdownSection
                title="Over time"
                description="Their readiness in each assessment they were part of."
            >
                <ScoreTrend
                    points={context.history}
                    guides={readinessThresholds(run)}
                    tierFill={(tier) =>
                        TIER_FILL[tier] ?? 'fill-muted-foreground'
                    }
                    tierLabels={TIER_LABELS}
                    noun="Readiness"
                />
            </BreakdownSection>
            <RecordPanels context={context} appraisalsFirst />
        </>
    );
}

/** What the outlook says, in words: what a repeat gives, and where tiers start. */
function outlookSummary(
    outlook: NonNullable<NonNullable<ReadinessScore['explanation']>['outlook']>,
    latest: number,
    current: number,
    tierOf: (score: number) => ReturnType<typeof tierOfScore>,
): string {
    const parts: string[] = [];

    if (outlook.high_from !== null) {
        parts.push(
            `A next appraisal of ${outlook.high_from}% or more reaches High.`,
        );
    } else {
        parts.push('No single appraisal reaches High from here.');
    }

    if (
        outlook.medium_from !== null &&
        outlook.medium_from !== outlook.high_from
    ) {
        parts.push(`Medium starts at ${outlook.medium_from}%.`);
    }

    if (outlook.same_again !== null) {
        const tier = tierOf(outlook.same_again);
        const repeat = `The same ${latest.toFixed(1)}% again would give ${Math.round(outlook.same_again)}${tier ? ` (${TIER_LABELS[tier]})` : ''}`;
        parts.push(
            outlook.same_again < current - 1
                ? `${repeat}: with no improvement to read, readiness falls, because the change on the previous appraisal is what the model weighs most.`
                : `${repeat}.`,
        );
    }

    return parts.join(' ');
}

function AppraisalGrid({ score }: { score: ReadinessScore }) {
    const [previous, latest] =
        score.history.length >= 2
            ? [score.history[score.history.length - 2], score.history.at(-1)]
            : [null, score.history.at(-1) ?? null];
    const change = score.features.rating_change;

    const cells: { label: string; value: string | null; hint?: string }[] = [
        {
            label: 'Latest appraisal',
            value:
                score.features.rating_latest === undefined
                    ? null
                    : `${score.features.rating_latest.toFixed(1)}%`,
            hint: latest?.label ?? undefined,
        },
        {
            label: 'Previous appraisal',
            value: previous ? `${previous.rating.toFixed(1)}%` : null,
            hint: previous?.label ?? undefined,
        },
        {
            label: 'Change',
            value: change === undefined ? null : formatChange(change),
        },
    ];

    return (
        <dl className="grid grid-cols-3 gap-2">
            {cells.map((cell) => (
                <div
                    key={cell.label}
                    className="flex flex-col gap-0.5 rounded-lg border border-sidebar-border/60 bg-card/40 px-3 py-2 dark:border-sidebar-border"
                >
                    <dt className="text-[11px] text-muted-foreground">
                        {cell.label}
                    </dt>
                    <dd
                        className={cn(
                            'text-sm font-medium tabular-nums',
                            cell.value === null &&
                                'font-normal text-muted-foreground italic',
                        )}
                    >
                        {cell.value ?? 'Not on record'}
                    </dd>
                    {cell.hint && (
                        <span className="truncate text-[10px] text-muted-foreground">
                            {cell.hint}
                        </span>
                    )}
                </div>
            ))}
        </dl>
    );
}
