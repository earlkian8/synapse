import type { LedgerStep } from '@/features/prediction-insights/components/contribution-ledger';
import { formatPoints } from '@/features/prediction-insights/lib';
import type { ChartGroup } from '@/features/prediction-insights/lib';
import { formatChange, TIER_LABELS } from './constants';
import type {
    ReadinessFactor,
    ReadinessRun,
    ReadinessScore,
    ReadinessTier,
} from './types';

/** The tiers as chart groups, Low → High. */
export const READINESS_GROUPS: readonly ChartGroup<ReadinessTier>[] = [
    {
        key: 'low',
        label: TIER_LABELS.low,
        fill: 'bg-slate-400',
        svgFill: 'fill-slate-400',
    },
    {
        key: 'medium',
        label: TIER_LABELS.medium,
        fill: 'bg-amber-500',
        svgFill: 'fill-amber-500',
    },
    {
        key: 'high',
        label: TIER_LABELS.high,
        fill: 'bg-emerald-500',
        svgFill: 'fill-emerald-500',
    },
];

/** Where the tiers begin on the score scale, when the run says. */
export function readinessThresholds(
    run: ReadinessRun,
): { value: number; label: string }[] {
    if (!run.reference) {
        return [];
    }

    return [
        { value: run.reference.tier_scores.medium, label: TIER_LABELS.medium },
        { value: run.reference.tier_scores.high, label: TIER_LABELS.high },
    ];
}

/** The tier a score on the run's scale falls in (for the outlook). */
export function tierOfScore(
    run: ReadinessRun,
    score: number,
): ReadinessTier | null {
    if (!run.reference) {
        return null;
    }

    if (score >= run.reference.tier_scores.high) {
        return 'high';
    }

    return score >= run.reference.tier_scores.medium ? 'medium' : 'low';
}

/** A factor's value as the page writes it: "76.0%" or "+15.0 pts". */
export function formatFactorValue(
    feature: ReadinessFactor['feature'],
    value: number,
): string {
    return feature === 'rating_change'
        ? formatChange(value)
        : `${value.toFixed(1)}%`;
}

/** The reasons as ledger rows, strongest first (a push up is good news here). */
export function ledgerSteps(score: ReadinessScore): LedgerStep[] {
    return score.factors.map((factor) => ({
        key: factor.feature,
        label: factor.label,
        detail:
            factor.value !== undefined && factor.typical !== undefined
                ? `${formatFactorValue(factor.feature, factor.value)} · typical ${formatFactorValue(factor.feature, factor.typical)}`
                : undefined,
        points: factor.impact,
        favourable: factor.impact >= 0,
    }));
}

/** The typical record a score is measured from, in words. */
export function typicalRecord(
    score: ReadinessScore,
    run: ReadinessRun,
): string {
    const typical = run.reference?.typical;

    if (!typical) {
        return 'Every input at its usual value';
    }

    return score.basis === 'two_appraisals'
        ? `${typical.rating_latest.toFixed(0)}% latest, ${formatChange(typical.rating_change)} on the one before`
        : `${typical.rating_latest.toFixed(0)}% latest appraisal`;
}

function phrase(factor: ReadinessFactor): string {
    if (factor.value === undefined) {
        return factor.label.toLowerCase();
    }

    if (factor.feature === 'rating_change') {
        const points = Math.abs(factor.value).toFixed(1);

        // "an 8.5-point", "an 11-point", "a 9-point".
        const article = /^(8|1[18](\.|$))/.test(points) ? 'an' : 'a';

        return factor.value >= 0
            ? `${article} ${points}-point improvement on the previous appraisal`
            : `${article} ${points}-point drop from the previous appraisal`;
    }

    return `a latest appraisal of ${factor.value.toFixed(1)}%`;
}

/** The score in a sentence or two: against a typical record, and what moved it. */
export function readinessSummary(score: ReadinessScore): string | null {
    if (!score.explanation) {
        return null;
    }

    const baseline = Math.round(score.explanation.baseline);
    const [first, second] = score.factors;
    const named = (f: ReadinessFactor) =>
        `${phrase(f)} (${formatPoints(f.impact)})`;
    const history =
        score.basis === 'two_appraisals'
            ? 'a typical record with two appraisals'
            : 'a typical record with one appraisal';

    let sentence = `${Math.round(score.score)} against ${baseline} for ${history}`;

    if (!first) {
        return `${sentence} — this record is close to typical on every input.`;
    }

    sentence += ` — ${first.impact >= 0 ? 'raised' : 'lowered'} most by ${named(first)}`;

    if (second) {
        sentence += `, ${second.impact >= 0 ? 'with' : 'against'} ${named(second)}`;
    }

    return `${sentence}.`;
}

/** For the list: the strongest reason, with its points when the run has them. */
export function strongestReason(score: ReadinessScore): string | null {
    const top = score.factors[0];

    if (!top) {
        return null;
    }

    return score.explanation
        ? `${top.label} ${formatPoints(top.impact)}`
        : `${top.label} ${top.direction === 'up' ? '↑' : '↓'}`;
}
