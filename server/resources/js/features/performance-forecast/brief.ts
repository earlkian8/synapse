import {
    daysSince,
    formatShare,
    shortDate,
} from '@/features/prediction-insights/lib';
import { SURFACES } from '@/features/prediction-insights/surfaces';
import type {
    NextStep,
    PredictionContext,
    Verdict,
} from '@/features/prediction-insights/types';
import { personHref } from '@/features/prediction-insights/use-prediction-context';
import type { ForecastRun, ForecastScore } from './types';

/**
 * The decision brief on one person's performance forecast (ADR 0099): where the
 * next appraisal is likely to land and how sure that is, once their readiness,
 * flight risk, appraisal sections and earlier forecasts are read beside it, and
 * what to do before the cycle. Plain rules over what is on record — no model call.
 */

const STALE_DAYS = 120;

/** A chance worth naming: an edge the next rating could tip over. */
const EDGE = 0.2;

/** The latest appraisal's weakest section, when it clearly trails the rest. */
export function weakestSection(context: PredictionContext | null) {
    const latest = context?.appraisals?.at(-1);

    if (!latest || latest.sections.length < 2) {
        return null;
    }

    const lowest = latest.sections.reduce((a, b) =>
        b.percent < a.percent ? b : a,
    );

    return lowest.percent < latest.percent - 3
        ? { ...lowest, appraisal: latest }
        : null;
}

/** How earlier forecasts for this person did, once their periods were appraised. */
export function personalTrackRecord(
    context: PredictionContext | null,
): { checked: number; inRange: number; meanMiss: number } | null {
    const checked = (context?.history ?? []).filter(
        (h) => h.actual != null && h.low != null && h.high != null,
    );

    if (checked.length === 0) {
        return null;
    }

    return {
        checked: checked.length,
        inRange: checked.filter(
            (h) => h.actual! >= h.low! && h.actual! <= h.high!,
        ).length,
        meanMiss:
            checked.reduce((sum, h) => sum + Math.abs(h.actual! - h.score), 0) /
            checked.length,
    };
}

export function forecastVerdict(
    score: ForecastScore,
    run: ForecastRun,
    context: PredictionContext | null,
): Verdict {
    if (context?.offboarding) {
        return {
            tone: 'closed',
            label: 'Exit in progress',
            headline:
                'They are leaving: an exit is already under way, so this forecast is unlikely to be tested by an appraisal.',
            notes: [],
        };
    }

    const chances = score.explanation?.chances ?? null;
    const readiness = context?.signals.promotion;
    const risk = context?.signals.attrition;
    const sure = formatShare(score.confidence);
    let read: Omit<Verdict, 'notes'>;

    if (score.band === 'below') {
        read = {
            tone: 'urgent',
            label: 'Plan support now',
            headline: `Likely to land below target (${sure} chance). Plan support before the cycle starts, while there is time to change the result.`,
        };
    } else if (score.band === 'exceeds') {
        read = {
            tone: 'positive',
            label: 'Expected to exceed',
            headline: `Expected to exceed expectations (${sure} chance).${
                readiness?.tier === 'high'
                    ? ' Promotion readiness is High too: worth a look at the next review.'
                    : risk?.tier === 'high'
                      ? ' Their flight risk is high: a strong performer to keep.'
                      : ' Worth stretching with more responsibility.'
            }`,
        };
    } else {
        const up = chances?.exceeds ?? 0;
        const down = chances?.below ?? 0;

        read = {
            tone: down >= EDGE ? 'watch' : 'calm',
            label: 'On track',
            headline: `Expected to meet expectations${
                up >= EDGE
                    ? `, with a real chance (${formatShare(up)}) of exceeding them.`
                    : down >= EDGE
                      ? ` — but a ${formatShare(down)} chance of slipping below target.`
                      : '.'
            }`,
        };
    }

    const notes: string[] = [];
    const latest = score.features.rating_latest;

    if (latest !== undefined && latest - score.predicted_rating > 3) {
        notes.push(
            `Forecasts pull toward the middle: after a rating as high as ${latest.toFixed(1)}%, the next is usually a little lower. It is not a sign of decline.`,
        );
    } else if (latest !== undefined && score.predicted_rating - latest > 3) {
        notes.push(
            `Forecasts pull toward the middle: after a rating as low as ${latest.toFixed(1)}%, the next is usually a little higher.`,
        );
    }

    const record = personalTrackRecord(context);

    if (record) {
        notes.push(
            `Earlier forecasts for them: ${record.inRange} of ${record.checked} landed in range, ${record.meanMiss.toFixed(1)} points off on average.`,
        );
    }

    const age = daysSince(run.created_at);

    if (age > STALE_DAYS) {
        notes.push(
            `This forecast was made ${age} days ago (${shortDate(run.created_at)}).`,
        );
    }

    return { ...read, notes };
}

export function forecastSteps(
    score: ForecastScore,
    run: ForecastRun,
    context: PredictionContext | null,
    employeeId: number,
): NextStep[] {
    if (context?.offboarding) {
        return [];
    }

    const steps: NextStep[] = [];
    const chances = score.explanation?.chances ?? null;
    const period = run.target_period?.name ?? 'the next cycle';
    const weakest = weakestSection(context);
    const training =
        context?.training !== null && context?.training !== undefined
            ? { href: '/training', label: 'Find training' }
            : undefined;

    if (score.band === 'below') {
        steps.push({
            key: 'goals',
            title: `Agree on clear goals for ${period}`,
            because: `Forecast ${Math.round(score.predicted_rating)}, below the 60 where On track starts${chances ? `; a ${formatShare(chances.below)} chance of landing below target` : ''}.`,
        });
    }

    if (weakest && score.band !== 'exceeds') {
        steps.push({
            key: 'develop',
            title: `Coach on ${weakest.name.toLowerCase()}`,
            because: `Their lowest section in ${weakest.appraisal.period ?? 'the latest appraisal'}: ${Math.round(weakest.percent)}%, against ${Math.round(weakest.appraisal.percent)}% overall.`,
            link: training,
        });
    }

    if (score.band === 'below' || (chances?.below ?? 0) >= EDGE) {
        steps.push({
            key: 'checkin',
            title: 'Schedule a check-in halfway through the cycle',
            because:
                'Halfway is early enough to see whether the support is working before the rating is set.',
        });
    }

    if (score.band === 'exceeds' || (chances?.exceeds ?? 0) >= EDGE) {
        steps.push({
            key: 'stretch',
            title: 'Offer a stretch goal or more responsibility',
            because:
                score.band === 'exceeds'
                    ? `A ${formatShare(score.confidence)} chance of exceeding expectations.`
                    : `A ${formatShare(chances!.exceeds)} chance of exceeding expectations.`,
        });
    }

    const readiness = context?.signals.promotion;

    if (readiness && readiness.tier !== 'low' && score.band === 'exceeds') {
        steps.push({
            key: 'readiness',
            title: 'Look at their promotion readiness',
            because: `Readiness ${Math.round(readiness.score)} (${SURFACES.promotion.labels[readiness.tier] ?? readiness.tier}).`,
            link: {
                href: personHref('promotion', employeeId),
                label: 'See their promotion readiness',
            },
        });
    }

    const risk = context?.signals.attrition;

    if (risk && risk.tier !== 'low') {
        steps.push({
            key: 'risk',
            title: 'Keep their flight risk in view',
            because: `Their attrition risk is ${Math.round(risk.score)} (${SURFACES.attrition.labels[risk.tier] ?? risk.tier}).`,
            link: {
                href: personHref('attrition', employeeId),
                label: 'See their attrition risk',
            },
        });
    }

    return steps.slice(0, 4);
}
