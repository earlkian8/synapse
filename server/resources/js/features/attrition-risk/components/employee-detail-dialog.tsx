import { PersonAvatar } from '@/components/person-avatar';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ContributionLedger } from '@/features/prediction-insights/components/contribution-ledger';
import { WorkforcePosition } from '@/features/prediction-insights/components/workforce-position';
import { focusDialogItself } from '@/features/prediction-insights/lib';
import { cn } from '@/lib/utils';
import {
    confidenceLabel,
    formatConfidence,
    formatScore,
    INPUT_FIELDS,
    scoreBarTone,
    scoreTone,
    TIER_DESCRIPTIONS,
} from '../constants';
import { ledgerSteps, riskSummary, riskThresholds } from '../explain';
import type { RiskRun, RiskScore } from '../types';
import { FactorList } from './factor-list';
import { RiskBadge } from './risk-badge';
import { SurveyEvidence } from './survey-evidence';

/**
 * A drill-down on one employee's attrition risk: the score and where it sits in the
 * run, why — as a walk from a typical employee's score, reason by reason — what the
 * survey shows for those reasons, and the record it was read from.
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
    const thresholds = riskThresholds(run);

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
                                Attrition-risk breakdown for{' '}
                                {score.employee.full_name}
                            </DialogDescription>
                        </DialogHeader>

                        {/* Risk headline, and where it sits in the run */}
                        <div className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card/60 p-4 dark:border-sidebar-border">
                            <div className="flex items-end justify-between">
                                <div className="flex items-baseline gap-1.5">
                                    <span
                                        className={cn(
                                            'text-4xl font-semibold tracking-tight',
                                            scoreTone(score.score),
                                        )}
                                    >
                                        {formatScore(score.score)}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        / 100 flight risk
                                    </span>
                                </div>
                                <RiskBadge tier={score.tier} />
                            </div>
                            <div className="h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    className={cn(
                                        'h-full rounded-full',
                                        scoreBarTone(score.score),
                                    )}
                                    style={{ width: `${score.score}%` }}
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {TIER_DESCRIPTIONS[score.tier]}
                            </p>
                            <WorkforcePosition
                                scores={run.scores.map((s) => s.score)}
                                value={score.score}
                                name={score.employee.full_name}
                                noun="risk"
                                thresholds={thresholds}
                            />
                        </div>

                        {/* Why */}
                        {score.explanation ? (
                            <section className="flex flex-col gap-3">
                                <div className="flex flex-col gap-1">
                                    <h3 className="text-sm font-semibold">
                                        Why {formatScore(score.score)}
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        {riskSummary(score)}
                                    </p>
                                </div>
                                <ContributionLedger
                                    label={`How ${score.employee.full_name}'s risk adds up from a typical employee's`}
                                    start={{
                                        label: 'A typical employee',
                                        detail: 'Every input at its usual value',
                                        value: score.explanation.baseline,
                                    }}
                                    steps={ledgerSteps(score)}
                                    end={{
                                        label: score.employee.full_name,
                                        value: score.score,
                                        fill: scoreBarTone(score.score),
                                    }}
                                    guides={thresholds}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Each reason is the risk points that input
                                    adds to or takes from a typical employee’s
                                    score, shared fairly where inputs work
                                    together, so they add up to this score.
                                </p>
                            </section>
                        ) : (
                            score.factors.length > 0 && (
                                <section className="flex flex-col gap-2">
                                    <h3 className="text-sm font-semibold">
                                        What moves this score
                                    </h3>
                                    <FactorList factors={score.factors} />
                                    <p className="text-xs text-muted-foreground">
                                        This run is from before scores were
                                        broken down into points. Run a new
                                        assessment to see the full breakdown.
                                    </p>
                                </section>
                            )
                        )}

                        {/* What the data shows */}
                        {run.reference?.evidence && (
                            <section className="flex flex-col gap-2">
                                <div className="flex flex-col gap-0.5">
                                    <h3 className="text-sm font-semibold">
                                        What the data shows
                                    </h3>
                                    <p className="text-xs text-muted-foreground">
                                        {run.reference.evidence.source ===
                                        'survey'
                                            ? `How often people left at each answer, among the ${run.reference.evidence.people} people surveyed that the model learned from (${Math.round((run.reference.evidence.overall_rate ?? 0) * 100)}% left overall).`
                                            : `How often people left at each value, among the ${run.reference.evidence.people} people in your own records that your model learned from.`}
                                    </p>
                                </div>
                                <SurveyEvidence
                                    factors={score.factors.filter(
                                        (f) => f.impact > 0,
                                    )}
                                    evidence={run.reference.evidence}
                                />
                            </section>
                        )}

                        {/* Their record */}
                        <section className="flex flex-col gap-2">
                            <div className="flex items-end justify-between gap-3">
                                <h3 className="text-sm font-semibold">
                                    What it was read from
                                </h3>
                                <span className="text-xs text-muted-foreground">
                                    {confidenceLabel(score.confidence)} ·{' '}
                                    {formatConfidence(score.confidence)} of the
                                    inputs on record
                                </span>
                            </div>
                            <FeatureGrid features={score.features} />
                            <p className="mt-1 text-xs text-muted-foreground">
                                A risk score is a prompt for a conversation, not
                                a verdict. It reflects patterns among people who
                                did and did not resign — it cannot see why this
                                person might, and no demographic or protected
                                attribute is used. An input not on record is
                                read as typical, so it never counts as a reason.
                            </p>
                        </section>
                    </>
                )}
            </DialogContent>
        </Dialog>
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
