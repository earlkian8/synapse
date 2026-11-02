import {
    chanceOfReaching,
    chanceWords,
    daysSince,
    formatPoints,
    shortDate,
    sinceLastRun,
} from '@/features/prediction-insights/lib';
import { SURFACES } from '@/features/prediction-insights/surfaces';
import type {
    NextStep,
    PredictionContext,
    Signal,
    Verdict,
} from '@/features/prediction-insights/types';
import { personHref } from '@/features/prediction-insights/use-prediction-context';
import { formatLift, REFERENCE_PROMOTION_RATE, TIER_LABELS } from './constants';
import type { ReadinessRun, ReadinessScore } from './types';

/**
 * The decision brief on one person's promotion readiness (ADR 0099): what the
 * score means for a promotion decision once their flight risk, their forecast and
 * their appraisals are read beside it, and what to do next. Plain rules over what
 * is on record — no model call.
 */

const STALE_DAYS = 60;
const NOTABLE_MOVE = 5;

/** The odds' average for this run: the organisation's own when its model scored. */
export function averageRate(run: ReadinessRun): number {
    return run.scored_by === 'own' && run.base_rate !== null
        ? run.base_rate
        : REFERENCE_PROMOTION_RATE;
}

/**
 * The chance their next appraisal reaches `target`, from the performance
 * forecast's range — or null when there is no forecast with a range.
 */
export function forecastChance(
    forecast: Signal | null | undefined,
    target: number | null,
): number | null {
    if (
        !forecast ||
        target === null ||
        forecast.low == null ||
        forecast.high == null
    ) {
        return null;
    }

    return chanceOfReaching(
        forecast.score,
        forecast.low,
        forecast.high,
        target,
    );
}

/** The next tier up and the next appraisal it starts at, if any. */
export function nextTarget(
    score: ReadinessScore,
): { tier: 'medium' | 'high'; from: number } | null {
    const outlook = score.explanation?.outlook;

    if (!outlook || score.tier === 'high') {
        return null;
    }

    if (score.tier === 'medium') {
        return outlook.high_from === null
            ? null
            : { tier: 'high', from: outlook.high_from };
    }

    return outlook.medium_from === null
        ? null
        : { tier: 'medium', from: outlook.medium_from };
}

export function promotionVerdict(
    score: ReadinessScore,
    run: ReadinessRun,
    context: PredictionContext | null,
): Verdict {
    const risk = context?.signals.attrition;
    const forecast = context?.signals.performance;
    const notes: string[] = [];

    if (context?.offboarding) {
        return {
            tone: 'closed',
            label: 'Exit in progress',
            headline:
                'They are leaving: an exit is already under way. Readiness stays on record, but there is no promotion decision to make now.',
            notes,
        };
    }

    const target = nextTarget(score);
    const chance = forecastChance(forecast, target?.from ?? null);
    const forecastClause =
        target && forecast && chance !== null
            ? ` Their forecast of ${Math.round(forecast.score)} (likely ${Math.round(forecast.low!)}–${Math.round(forecast.high!)}) makes that ${chanceWords(chance)}.`
            : '';

    let read: Omit<Verdict, 'notes'>;

    if (score.tier === 'high') {
        read = {
            tone: 'positive',
            label: 'Ready to put forward',
            headline:
                risk?.tier === 'high'
                    ? `Promotion odds ${formatLift(score.probability, averageRate(run))} the average — and at high flight risk, so a slow decision has a cost. Bring this to the next review.`
                    : risk?.tier === 'medium'
                      ? `Promotion odds ${formatLift(score.probability, averageRate(run))} the average, with some flight risk too. A clear conversation about their path is worth having soon.`
                      : `Promotion odds ${formatLift(score.probability, averageRate(run))} the average. A strong candidate for the next promotion review.`,
        };
    } else if (score.tier === 'medium') {
        read = {
            tone: 'watch',
            label: 'Developing',
            headline: target
                ? `At or above average odds. The next appraisal decides: ${target.from}% or more reaches High.${forecastClause}`
                : 'At or above average odds. No single appraisal reaches High from here; it will take more than one strong cycle.',
        };
    } else {
        read = {
            tone: 'calm',
            label: 'Not yet',
            headline: target
                ? `Below average odds of promotion. A next appraisal of ${target.from}% or more reaches Medium.${forecastClause}`
                : 'Below average odds of promotion, and no single appraisal reaches Medium from here.',
        };
    }

    if (score.basis === 'latest_appraisal') {
        notes.push(
            'Based on one appraisal: the change between two, which the model weighs most, is not known yet.',
        );
    }

    const outlook = score.explanation?.outlook;

    if (outlook?.same_again != null && outlook.same_again < score.score - 5) {
        notes.push(
            `The same rating again would give ${Math.round(outlook.same_again)}: with no improvement to read, readiness falls.`,
        );
    }

    if (score.tier === 'high' && forecast?.tier === 'exceeds') {
        notes.push(
            `Forecast to exceed expectations again (${Math.round(forecast.score)}).`,
        );
    }

    const moved = context ? sinceLastRun(context.history) : null;

    if (moved && Math.abs(moved.points) >= NOTABLE_MOVE) {
        notes.push(
            `${moved.points > 0 ? 'Up' : 'Down'} ${Math.round(Math.abs(moved.points))} points since the run of ${shortDate(moved.since)}.`,
        );
    }

    const age = daysSince(run.created_at);

    if (age > STALE_DAYS) {
        notes.push(
            `This assessment is ${age} days old. Run a new one after the latest appraisals are completed.`,
        );
    }

    return { ...read, notes };
}

export function promotionSteps(
    score: ReadinessScore,
    run: ReadinessRun,
    context: PredictionContext | null,
    employeeId: number,
): NextStep[] {
    if (context?.offboarding) {
        return [];
    }

    const steps: NextStep[] = [];
    const risk = context?.signals.attrition;
    const riskStep = (title: string): NextStep | null =>
        risk && risk.tier !== 'low'
            ? {
                  key: 'risk',
                  title,
                  because: `Their attrition risk is ${Math.round(risk.score)} (${SURFACES.attrition.labels[risk.tier] ?? risk.tier}).`,
                  link: {
                      href: personHref('attrition', employeeId),
                      label: 'See their attrition risk',
                  },
              }
            : null;

    if (score.tier === 'high') {
        steps.push({
            key: 'review',
            title: 'Put them forward at the next promotion review',
            because: `Readiness ${Math.round(score.score)}: promotion odds ${formatLift(score.probability, averageRate(run))} the average among records like theirs.`,
        });

        const quick = riskStep('Move quickly');

        if (quick) {
            steps.push(quick);
        }

        steps.push({
            key: 'role',
            title: 'Agree on what the next role would be',
            because:
                'The record supports a promotion; it cannot see whether a role is open or what the person wants next.',
        });

        return steps;
    }

    const target = nextTarget(score);

    if (target) {
        steps.push({
            key: 'goal',
            title: `Set a goal for the next appraisal: ${target.from}% or more`,
            because: `That is where ${TIER_LABELS[target.tier]} starts. The next rating moves readiness more than anything else on record.`,
        });
    }

    const latest = context?.appraisals?.at(-1);
    const lowest =
        latest && latest.sections.length > 1
            ? latest.sections.reduce((a, b) => (b.percent < a.percent ? b : a))
            : null;

    if (latest && lowest && lowest.percent < latest.percent - 3) {
        steps.push({
            key: 'develop',
            title: `Focus development on ${lowest.name.toLowerCase()}`,
            because: `Their lowest section in ${latest.period ?? 'the latest appraisal'}: ${Math.round(lowest.percent)}%, against ${Math.round(latest.percent)}% overall.`,
            link:
                context?.training !== null
                    ? { href: '/training', label: 'Find training' }
                    : undefined,
        });
    }

    const change = score.features.rating_change;
    const changeFactor = score.factors.find(
        (f) => f.feature === 'rating_change',
    );

    if (change !== undefined && change < -2) {
        steps.push({
            key: 'drop',
            title: 'Ask what changed since the previous appraisal',
            because: `Down ${Math.abs(change).toFixed(1)} points on the one before${changeFactor && score.explanation ? `, which takes ${formatPoints(changeFactor.impact).replace('−', '')} points off readiness` : ''}.`,
        });
    }

    if (score.basis === 'latest_appraisal') {
        steps.push({
            key: 'revisit',
            title: 'Revisit after the next appraisal',
            because:
                'With two appraisals the score reads the change between them, the strongest signal there is.',
        });
    }

    const mind = riskStep('Keep their flight risk in view');

    if (mind) {
        steps.push(mind);
    }

    return steps.slice(0, 4);
}
