import {
    daysSince,
    formatPoints,
    formatShare,
    shortDate,
    sinceLastRun,
} from '@/features/prediction-insights/lib';
import { SURFACES } from '@/features/prediction-insights/surfaces';
import type {
    NextStep,
    PredictionContext,
    Verdict,
} from '@/features/prediction-insights/types';
import { personHref } from '@/features/prediction-insights/use-prediction-context';
import { formatInput, neverPromoted, reasonPhrase } from './explain';
import type { RiskFactor, RiskRun, RiskScenario, RiskScore } from './types';

/**
 * The decision brief on one person's attrition risk (ADR 0099): how urgent it is
 * once their other predictions and record are read beside it, what to do next and
 * why, and what the model would score with a change HR can make. Plain rules over
 * what is on record — no model call, and nothing said that the record does not show.
 */

/** Withdrawal's levels, least to most (synapse_ml.attrition.indicators). */
const WITHDRAWAL_LEVELS = ['None', 'Low', 'Moderate', 'High'] as const;

/** A run older than this is flagged as possibly out of date. */
const STALE_DAYS = 60;

/** Moves smaller than this between runs are not called out. */
const NOTABLE_MOVE = 5;

const EXIT_NOUNS: Record<string, string> = {
    resignation: 'A resignation',
    termination: 'A termination',
    retirement: 'A retirement',
    end_of_contract: 'An end of contract',
};

/** How strong a performer the other screens say this person is. */
function strength(
    context: PredictionContext | null,
): 'strong' | 'weak' | 'unknown' {
    const forecast = context?.signals.performance;
    const readiness = context?.signals.promotion;

    if (forecast?.tier === 'exceeds' || readiness?.tier === 'high') {
        return 'strong';
    }

    if (forecast?.tier === 'below') {
        return 'weak';
    }

    return 'unknown';
}

/** The share who left in a band, as a clause, when the data agrees with the model. */
function evidenceClause(factor: RiskFactor): string {
    const e = factor.evidence;

    if (!e || e.consistent === false || !factor.band) {
        return '';
    }

    const typical =
        e.typical_rate !== null && factor.typical_band
            ? `, against ${formatShare(e.typical_rate)} at “${factor.typical_band}”`
            : '';

    return ` In the data the model learned from, ${formatShare(e.rate)} of people at “${factor.band}” left${typical}.`;
}

export function attritionVerdict(
    score: RiskScore,
    run: RiskRun,
    context: PredictionContext | null,
): Verdict {
    if (context?.offboarding) {
        const exit = context.offboarding;

        return {
            tone: 'closed',
            label: 'Exit in progress',
            headline: `${EXIT_NOUNS[exit.type] ?? 'An exit'} is already under way${exit.last_working_day ? `, with a last day of ${shortDate(exit.last_working_day)}` : ''}. The score no longer predicts anything here; the exit interview is where to learn why.`,
            notes: [],
        };
    }

    const value = strength(context);
    const forecast = context?.signals.performance;
    const readiness = context?.signals.promotion;
    const strongWhy =
        forecast?.tier === 'exceeds'
            ? 'forecast to exceed expectations'
            : `promotion readiness ${SURFACES.promotion.labels[readiness?.tier ?? ''] ?? ''}`.trim();

    const read: Record<RiskScore['tier'], Omit<Verdict, 'notes'>> = {
        high: {
            tone: 'urgent',
            label: 'Act now',
            headline:
                value === 'strong'
                    ? `Likely to leave, and a strong performer (${strongWhy}): a loss the team would feel. Talk with them this week.`
                    : value === 'weak'
                      ? 'Likely to leave, with a forecast below target. Weigh a retention effort alongside a conversation about performance.'
                      : 'Likely to leave. A conversation this week can surface what is behind it before a decision is made.',
        },
        medium: {
            tone: 'watch',
            label: 'Check in soon',
            headline:
                value === 'strong'
                    ? `Some risk of leaving, and a strong performer (${strongWhy}). Worth a check-in in the next few weeks.`
                    : 'Some risk of leaving. A check-in in the next few weeks can catch a concern early.',
        },
        low: {
            tone: 'calm',
            label: 'No action needed',
            headline:
                value === 'strong'
                    ? `Settled for now, and a strong performer (${strongWhy}). Keep recognising the work.`
                    : 'Settled for now. Nothing on record points to leaving soon.',
        },
    };

    const notes: string[] = [];
    const moved = context ? sinceLastRun(context.history) : null;

    if (moved && Math.abs(moved.points) >= NOTABLE_MOVE) {
        notes.push(
            `${moved.points > 0 ? 'Up' : 'Down'} ${Math.round(Math.abs(moved.points))} points since the run of ${shortDate(moved.since)}.`,
        );
    }

    const top = score.factors.find((f) => f.impact > 0);

    if (top && score.explanation) {
        notes.push(
            `Raised most by ${reasonPhrase(top, score.features)}, ${formatPoints(top.impact * 100)} points.`,
        );
    }

    if (score.confidence < 0.75) {
        notes.push(
            `Read with care: ${Math.round(score.confidence * 8)} of the 8 inputs are on record. The rest are taken as typical, so they are never a reason.`,
        );
    }

    const age = daysSince(run.created_at);

    if (age > STALE_DAYS) {
        notes.push(
            `This assessment is ${age} days old. Run a new one for a current read.`,
        );
    }

    return { ...read[score.tier], notes };
}

/**
 * What to do next, strongest reason first. Each step names the reason on record
 * behind it; a reason the data itself does not back (the model reads it through
 * the person's other answers) prompts no step of its own.
 */
export function attritionSteps(
    score: RiskScore,
    context: PredictionContext | null,
    employeeId: number,
): NextStep[] {
    if (context?.offboarding) {
        return [
            {
                key: 'exit-interview',
                title: 'Hold the exit interview',
                because:
                    'Why they are leaving is the most direct evidence there is, for this person and for whoever is at risk next.',
                link: { href: '/offboarding', label: 'Open Offboarding' },
            },
        ];
    }

    const steps: NextStep[] = [];
    const prompts: string[] = [];
    // Two reasons can call for the same step: the second adds its reason to it.
    const add = (step: NextStep) => {
        const same = steps.find((s) => s.key === step.key);

        if (same) {
            same.because = `${same.because} ${step.because}`;
        } else {
            steps.push(step);
        }
    };

    for (const factor of score.factors.filter((f) => f.impact > 0)) {
        const points = `${formatPoints(factor.impact * 100)} points`;
        const value = factor.value;
        const typical = factor.typical;
        const below =
            value !== undefined &&
            typical !== undefined &&
            Number(value) < Number(typical);
        const evidence = evidenceClause(factor);

        switch (factor.feature) {
            case 'years_since_promotion':
            case 'ever_promoted': {
                const never = neverPromoted(score.features);
                const wait = Number(score.features.years_since_promotion ?? 0);
                // Never promoted and new: the wait is just their tenure so far.
                const settling = never && wait < 1;
                // Promoted, and recently: the new role is the question.
                const recent =
                    !never &&
                    factor.feature === 'years_since_promotion' &&
                    below;
                const what =
                    factor.feature === 'ever_promoted'
                        ? 'Never having been promoted here'
                        : never
                          ? `${formatInput('years_since_promotion', wait)} here without a promotion`
                          : `${formatInput('years_since_promotion', value ?? 0)} since the last promotion`;

                if (settling) {
                    add({
                        key: 'settling',
                        title: 'Check in on how they are settling in',
                        because: `${what} adds ${points}.${evidence}`,
                    });
                    prompts.push(
                        'Is the job what you expected when you joined?',
                    );
                } else if (recent) {
                    add({
                        key: 'new-role',
                        title: 'Check in on how the new role is going',
                        because: `Promoted ${formatInput('years_since_promotion', value!)} ago, which adds ${points}.${evidence}`,
                    });
                    prompts.push(
                        'How is the new role compared with what you expected?',
                    );
                } else {
                    const readiness = context?.signals.promotion;
                    add({
                        key: 'career',
                        title: 'Talk about their next step',
                        because: `${what} adds ${points}.${evidence}${readiness ? ` Their promotion readiness is ${SURFACES.promotion.labels[readiness.tier] ?? readiness.tier} (${Math.round(readiness.score)}).` : ''}`,
                        link: readiness
                            ? {
                                  href: personHref('promotion', employeeId),
                                  label: 'See their promotion readiness',
                              }
                            : undefined,
                    });
                    prompts.push(
                        'Where do you want to be in a year, and do you see a way there here?',
                    );
                }

                break;
            }

            case 'monthly_salary':
                add({
                    key: 'pay',
                    title: 'Review their pay against the role',
                    because: `Their pay adds ${points}.${evidence}`,
                });
                prompts.push('Do you feel fairly paid for the work you do?');
                break;

            case 'tenure_years':
                add(
                    below
                        ? {
                              key: 'settling',
                              title: 'Check in on how they are settling in',
                              because: `A tenure of ${formatInput('tenure_years', value!)} adds ${points}.${evidence}`,
                          }
                        : {
                              key: 'growth',
                              title: 'Ask what would keep them growing here',
                              because: `A tenure of ${value !== undefined ? formatInput('tenure_years', value) : 'this length'} adds ${points}.${evidence}`,
                          },
                );
                prompts.push(
                    below
                        ? 'Is the job what you expected when you joined?'
                        : 'What would make the next few years here worth staying for?',
                );
                break;

            case 'employment_type':
                if (value === 'probationary' || value === 'contractual') {
                    add({
                        key: 'regular',
                        title: 'Discuss a path to regular employment',
                        because: `${formatInput('employment_type', value)} employment adds ${points}.${evidence}`,
                    });
                    prompts.push(
                        'What would a regular position change for you?',
                    );
                } else if (value === 'part_time') {
                    add({
                        key: 'arrangement',
                        title: 'Ask whether part-time still suits them',
                        because: `Part-time employment adds ${points}.${evidence}`,
                    });
                }

                break;

            case 'withdrawal': {
                // Less withdrawn than typical and still raising risk is the survey's
                // own pattern, explained under Reasons — not something to act on.
                const levels = WITHDRAWAL_LEVELS as readonly string[];

                if (
                    levels.indexOf(String(value)) >
                    levels.indexOf(String(typical))
                ) {
                    const phrase = reasonPhrase(factor, score.features);
                    add({
                        key: 'withdrawal',
                        title: 'Ask what is behind the absences and late arrivals',
                        because: `${phrase.charAt(0).toUpperCase()}${phrase.slice(1)} adds ${points}. Read together they indicate withdrawal, which often comes before a resignation — a sign to ask about, not a verdict.${evidence}`,
                    });
                    prompts.push(
                        'Is anything making it hard to be at work right now?',
                    );
                }

                break;
            }

            case 'absences_90d':
            case 'lates_90d':
                // Fewer than typical and still raising risk is the survey's own
                // pattern, explained under Reasons — not something to act on.
                if (!below && value !== undefined && Number(value) > 0) {
                    const absences = factor.feature === 'absences_90d';
                    add({
                        key: factor.feature,
                        title: absences
                            ? 'Ask what is behind the recent absences'
                            : 'Ask what is behind the late arrivals',
                        because: `${formatInput(factor.feature, value)} ${absences ? 'absent' : 'late'} in the last 90 days adds ${points}.${evidence}`,
                    });
                    prompts.push(
                        'Is anything making it hard to be at work right now?',
                    );
                }

                break;
        }
    }

    if (score.tier !== 'low') {
        steps.unshift({
            key: 'stay',
            title: 'Hold a stay conversation',
            because:
                'Ask before they decide. The model cannot see their workload, their manager, or offers from elsewhere; they can tell you.',
            prompts: [
                ...new Set([
                    ...prompts,
                    'What would make you think about leaving?',
                ]),
            ].slice(0, 3),
        });
    } else if (context?.awards && context.awards.length === 0) {
        steps.push({
            key: 'recognise',
            title: 'Recognise their work',
            because:
                'No award on record in the last two years. Recognition is cheap, and it is easier to keep a settled person settled.',
            link: { href: '/awards', label: 'Open Awards' },
        });
    }

    return steps.slice(0, 4);
}

/** A scenario as the page names it, and what it changes. */
export function scenarioLabel(
    scenario: RiskScenario,
    score: RiskScore,
): { title: string; detail: string } {
    switch (scenario.key) {
        case 'promotion':
            return {
                title: 'A promotion now',
                detail:
                    score.features.ever_promoted !== undefined &&
                    Number(score.features.ever_promoted) > 0
                        ? `Since last promotion from ${formatInput('years_since_promotion', score.features.years_since_promotion ?? 0)} to none`
                        : 'Promoted here for the first time',
            };
        case 'regular':
            return {
                title: 'Regular employment',
                detail: `From ${formatInput('employment_type', score.features.employment_type ?? '').toLowerCase()}`,
            };
        case 'pay_band':
            return {
                title: 'A raise into the next pay band',
                detail: `Monthly pay of ${formatInput('monthly_salary', scenario.changes.monthly_salary)}, the lowest figure the model reads as that band`,
            };
        default:
            return {
                title: 'All of these together',
                detail: 'Every change above at once',
            };
    }
}
