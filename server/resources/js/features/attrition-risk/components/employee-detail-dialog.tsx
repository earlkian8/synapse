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
import { RecordPanels } from '@/features/prediction-insights/components/record-panels';
import { ScoreHeadline } from '@/features/prediction-insights/components/score-headline';
import { ScoreTrend } from '@/features/prediction-insights/components/score-trend';
import { VerdictCard } from '@/features/prediction-insights/components/verdict-card';
import { peerGroups, sinceLastRun } from '@/features/prediction-insights/lib';
import type { PredictionContext } from '@/features/prediction-insights/types';
import { usePredictionContext } from '@/features/prediction-insights/use-prediction-context';
import type { PredictionContextState } from '@/features/prediction-insights/use-prediction-context';
import { cn } from '@/lib/utils';
import { attritionSteps, attritionVerdict } from '../brief';
import {
    confidenceLabel,
    formatConfidence,
    INPUT_FIELDS,
    scoreBarTone,
    scoreTone,
    TIER_DESCRIPTIONS,
    TIER_LABELS,
} from '../constants';
import {
    ledgerSteps,
    RISK_GROUPS,
    riskSummary,
    riskThresholds,
} from '../explain';
import type { RiskRun, RiskScore } from '../types';
import { FactorList } from './factor-list';
import { RiskBadge } from './risk-badge';
import { ScenarioList } from './scenario-list';
import { SurveyEvidence } from './survey-evidence';

const TIER_FILL = Object.fromEntries(
    RISK_GROUPS.map((g) => [g.key, g.svgFill]),
);

/**
 * One person's attrition risk as a decision brief (ADR 0099). Summary: the score,
 * the read once their other predictions and record are weighed in, next steps tied
 * to the reasons, and where they stand among their peers. Reasons: the walk from a
 * typical employee's score and what the survey shows. What would lower it: the
 * model's score with a change HR can make. Record: their trend across runs and the
 * record around the model's inputs, as far as the viewer may see it.
 */
export function EmployeeDetailDialog({
    score,
    run,
    onOpenChange,
}: {
    score: RiskScore | null;
    run: RiskRun;
    onOpenChange: (open: boolean) => void;
}) {
    const employee = score?.employee ?? null;
    const state = usePredictionContext('attrition', employee?.id ?? null);
    const context = state.status === 'ready' ? state.context : null;

    return (
        <BreakdownDialog
            person={employee}
            description={`Attrition-risk breakdown for ${employee?.full_name ?? 'this employee'}`}
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
                              content: <Reasons score={score} run={run} />,
                          },
                          {
                              key: 'what-if',
                              label: 'What would lower it',
                              content: <WhatIf score={score} run={run} />,
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
    score: RiskScore;
    run: RiskRun;
    state: PredictionContextState;
}) {
    const employee = score.employee!;
    const thresholds = riskThresholds(run);
    const settled = state.status !== 'loading';
    const context = state.context;

    return (
        <>
            <ScoreHeadline
                value={score.score}
                unit="/ 100 flight risk"
                tone={scoreTone(score.score)}
                fill={scoreBarTone(score.score)}
                badge={<RiskBadge tier={score.tier} />}
                guides={thresholds}
                moved={context ? sinceLastRun(context.history) : null}
                upIsGood={false}
            >
                {TIER_DESCRIPTIONS[score.tier]}.{' '}
                {run.reference
                    ? `A typical employee scores ${Math.round(run.reference.baseline)}.`
                    : ''}
            </ScoreHeadline>

            {settled ? (
                <VerdictCard verdict={attritionVerdict(score, run, context)} />
            ) : (
                <Skeleton className="h-28 w-full rounded-xl" />
            )}

            <BreakdownSection title="Suggested next steps">
                {settled ? (
                    <NextSteps
                        steps={attritionSteps(score, context, employee.id)}
                    />
                ) : (
                    <Skeleton className="h-32 w-full rounded-xl" />
                )}
            </BreakdownSection>

            {context && Object.keys(context.signals).length > 0 && (
                <BreakdownSection
                    title="Their other predictions"
                    description="A risk weighs differently for someone who is also ready for promotion, or forecast to exceed expectations."
                >
                    <OtherPredictions
                        signals={context.signals}
                        employeeId={employee.id}
                    />
                </BreakdownSection>
            )}

            <BreakdownSection
                title="Compared with"
                description="Everyone's risk in this run, their department and their position."
            >
                <PeerComparison
                    groups={peerGroups(run.scores, employee)}
                    value={score.score}
                    name={employee.full_name}
                    noun="risk"
                />
            </BreakdownSection>
        </>
    );
}

function Reasons({ score, run }: { score: RiskScore; run: RiskRun }) {
    const employee = score.employee!;
    const thresholds = riskThresholds(run);
    const evidence = run.reference?.evidence ?? null;

    return (
        <>
            {score.explanation ? (
                <BreakdownSection
                    title={`Why ${Math.round(score.score)}`}
                    description={riskSummary(score)}
                >
                    <ContributionLedger
                        label={`How ${employee.full_name}'s risk adds up from a typical employee's`}
                        start={{
                            label: 'A typical employee',
                            detail: 'Every input at its usual value',
                            value: score.explanation.baseline,
                        }}
                        steps={ledgerSteps(score)}
                        end={{
                            label: employee.full_name,
                            value: score.score,
                            fill: scoreBarTone(score.score),
                        }}
                        guides={thresholds}
                    />
                    <p className="text-xs text-muted-foreground">
                        Each reason is the risk points that input adds to or
                        takes from a typical employee’s score, shared fairly
                        where inputs work together, so they add up to this
                        score.
                    </p>
                </BreakdownSection>
            ) : (
                score.factors.length > 0 && (
                    <BreakdownSection
                        title="What moves this score"
                        description="This run is from before scores were broken down into points. Run a new assessment to see the full breakdown."
                    >
                        <FactorList factors={score.factors} />
                    </BreakdownSection>
                )
            )}

            {evidence && (
                <BreakdownSection
                    title="What the data shows"
                    description={
                        evidence.source === 'survey'
                            ? `How often people left at each answer, among the ${evidence.people} people surveyed that the model learned from (${Math.round((evidence.overall_rate ?? 0) * 100)}% left overall).`
                            : `How often people left at each value, among the ${evidence.people} people in your own records that your model learned from.`
                    }
                >
                    <SurveyEvidence
                        factors={score.factors}
                        evidence={evidence}
                    />
                </BreakdownSection>
            )}

            <BreakdownSection
                title="What it was read from"
                aside={`${confidenceLabel(score.confidence)} · ${formatConfidence(score.confidence)} of the inputs on record`}
            >
                <FeatureGrid features={score.features} />
                <p className="text-xs text-muted-foreground">
                    A risk score is a prompt for a conversation, not a verdict.
                    It reflects patterns among people who did and did not resign
                    — it cannot see why this person might, and no demographic or
                    protected attribute is used. An input not on record is read
                    as typical, so it never counts as a reason.
                </p>
            </BreakdownSection>
        </>
    );
}

function WhatIf({ score, run }: { score: RiskScore; run: RiskRun }) {
    const scenarios = score.explanation?.scenarios ?? [];
    const best = [...scenarios].sort((a, b) => a.score - b.score)[0];

    return (
        <>
            <BreakdownSection
                title="What would lower it"
                description={
                    scenarios.length === 0
                        ? undefined
                        : best && best.score < score.score - 0.5
                          ? `What the model would score if one thing on their record changed. The biggest drop is ${Math.round(score.score - best.score)} points.`
                          : 'What the model would score if one thing on their record changed. None of these would lower it.'
                }
            >
                {scenarios.length > 0 ? (
                    <>
                        <ScenarioList
                            score={score}
                            guides={riskThresholds(run)}
                        />
                        <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-muted-foreground">
                            <span className="flex items-center gap-1.5">
                                <span className="size-2.5 rounded-full border-2 border-foreground/60 bg-card" />
                                Today
                            </span>
                            <span className="flex items-center gap-1.5">
                                <span className="size-2.5 rounded-full bg-foreground" />
                                With the change
                            </span>
                        </p>
                    </>
                ) : (
                    <p className="rounded-xl border border-dashed border-sidebar-border/70 px-4 py-5 text-sm text-muted-foreground dark:border-sidebar-border">
                        {score.explanation
                            ? 'None of the changes the model can weigh — a promotion, regular employment, a raise into the next pay band — applies to this record, or the inputs they change are not on record.'
                            : 'This run is from before scenarios were scored. Run a new assessment to see what would lower this score.'}
                    </p>
                )}
                <p className="text-xs leading-relaxed text-muted-foreground">
                    These show what the model weighs, not what will happen. A
                    promotion or a raise works through a person, not a formula,
                    and the data the model learned from links them with leaving
                    in ways that are not always the obvious ones.
                </p>
            </BreakdownSection>

            <BreakdownSection
                title="What the model cannot see"
                description="Ask about these in the conversation; they are often the real reason."
            >
                <ul className="grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
                    {[
                        'How they get on with their manager and team',
                        'Workload and hours beyond recorded overtime',
                        'Whether the work still interests them',
                        'Offers or approaches from elsewhere',
                        'What is happening outside work',
                        'How they feel about their pay next to their peers',
                    ].map((item) => (
                        <li key={item} className="flex items-start gap-2">
                            <span
                                aria-hidden
                                className="mt-2 size-1 shrink-0 rounded-full bg-muted-foreground"
                            />
                            {item}
                        </li>
                    ))}
                </ul>
            </BreakdownSection>
        </>
    );
}

function Record({
    run,
    context,
}: {
    run: RiskRun;
    context: PredictionContext;
}) {
    return (
        <>
            <BreakdownSection
                title="Over time"
                description="Their risk in each assessment they were part of."
            >
                <ScoreTrend
                    points={context.history}
                    guides={riskThresholds(run)}
                    tierFill={(tier) =>
                        TIER_FILL[tier] ?? 'fill-muted-foreground'
                    }
                    tierLabels={TIER_LABELS}
                    noun="Risk"
                />
            </BreakdownSection>
            <RecordPanels context={context} />
        </>
    );
}

/** Recorded and sent, but not read by the model (its leave rate is flat across bands). */
const NOT_READ = new Set(['overtime_hours_90d']);

function FeatureGrid({
    features,
}: {
    features: Record<string, number | string>;
}) {
    return (
        <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {INPUT_FIELDS.map((field) => {
                const value = features[field.key];
                const recorded = value !== undefined && value !== null;

                return (
                    <div
                        key={field.key}
                        className="flex flex-col gap-0.5 rounded-lg border border-sidebar-border/60 bg-card/40 px-3 py-2 dark:border-sidebar-border"
                    >
                        <dt className="text-[11px] text-muted-foreground">
                            {field.label}
                        </dt>
                        <dd
                            className={cn(
                                'text-sm font-medium tabular-nums',
                                !recorded &&
                                    'font-normal text-muted-foreground italic',
                            )}
                        >
                            {recorded ? field.format(value) : 'Not on record'}
                        </dd>
                        {NOT_READ.has(field.key) && (
                            <span className="text-[10px] text-muted-foreground">
                                Not used by the model
                            </span>
                        )}
                    </div>
                );
            })}
        </dl>
    );
}
