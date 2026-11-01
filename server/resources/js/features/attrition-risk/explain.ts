import type { LedgerStep } from '@/features/prediction-insights/components/contribution-ledger';
import type { DriverRow } from '@/features/prediction-insights/components/driver-chart';
import {
    formatPoints,
    joinWords,
    tallyBy,
} from '@/features/prediction-insights/lib';
import type { ChartGroup } from '@/features/prediction-insights/lib';
import { INPUT_FIELDS, TIER_LABELS } from './constants';
import type { RiskFactor, RiskRun, RiskScore, RiskTier } from './types';

const FIELDS = Object.fromEntries(INPUT_FIELDS.map((f) => [f.key, f]));

/** The tiers as chart groups, Stable → High risk. */
export const RISK_GROUPS: readonly ChartGroup<RiskTier>[] = [
    {
        key: 'low',
        label: TIER_LABELS.low,
        fill: 'bg-emerald-500',
        svgFill: 'fill-emerald-500',
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
        fill: 'bg-rose-500',
        svgFill: 'fill-rose-500',
    },
];

/** Where the tiers begin on the 0–100 scale (the service's cuts: 0.33 / 0.66). */
export function riskThresholds(
    run: RiskRun,
): { value: number; label: string }[] {
    const tiers = run.reference?.tiers ?? { medium_from: 33, high_from: 66 };

    return [
        { value: tiers.medium_from, label: TIER_LABELS.medium },
        { value: tiers.high_from, label: TIER_LABELS.high },
    ];
}

/** An input's label as the page names it ("Since last promotion"). */
export function inputLabel(
    factor: Pick<RiskFactor, 'feature' | 'label'>,
): string {
    return FIELDS[factor.feature]?.label ?? factor.label;
}

/** A recorded value as the page shows it ("8 mos", "₱22,076", "Never"). */
export function formatInput(feature: string, value: number | string): string {
    return FIELDS[feature]?.format(value) ?? String(value);
}

/** The risk points a factor adds (+) or takes away (−). */
export function factorPoints(factor: RiskFactor): number {
    return factor.impact * 100;
}

/**
 * The employee's value against the typical one, in the survey's own bands for
 * numbers ("8 mos · typical 3–5 yrs") and as recorded for answers ("Yes · typical
 * never").
 */
export function factorDetail(factor: RiskFactor): string | undefined {
    if (factor.value === undefined) {
        return undefined;
    }

    const own = formatInput(factor.feature, factor.value);
    const numeric = !['employment_type', 'ever_promoted'].includes(
        factor.feature,
    );
    const typical = numeric
        ? factor.typical_band
        : factor.typical !== undefined
          ? formatInput(factor.feature, factor.typical).toLowerCase()
          : null;

    return typical ? `${own} · typical ${typical}` : own;
}

/** A reason in a sentence: what about the employee's record it is. */
function phrase(factor: RiskFactor): string {
    const value = factor.value;

    if (value === undefined) {
        return inputLabel(factor).toLowerCase();
    }

    switch (factor.feature) {
        case 'tenure_years':
            return `a tenure of ${formatInput('tenure_years', value)}`;
        case 'years_since_promotion':
            return `${formatInput('years_since_promotion', value)} since the last promotion`;
        case 'ever_promoted':
            return Number(value) > 0
                ? 'having been promoted here before'
                : 'never having been promoted here';
        case 'monthly_salary':
            return 'their pay';
        case 'absences_90d':
            return Number(value) === 0
                ? 'no absences in 90 days'
                : `${formatInput('absences_90d', value)} absent in 90 days`;
        case 'lates_90d':
            return Number(value) === 0
                ? 'no late arrivals in 90 days'
                : `${formatInput('lates_90d', value).replace('time', 'late arrival')} in 90 days`;
        case 'employment_type':
            return `${formatInput('employment_type', value).toLowerCase()} employment`;
        default:
            return inputLabel(factor).toLowerCase();
    }
}

/** The reasons as ledger rows, strongest first (a push up is bad news here). */
export function ledgerSteps(score: RiskScore): LedgerStep[] {
    return score.factors.map((factor) => ({
        key: factor.feature,
        label: inputLabel(factor),
        detail: factorDetail(factor),
        points: factorPoints(factor),
        favourable: factorPoints(factor) < 0,
    }));
}

/**
 * The score in one or two plain sentences: where it sits against a typical
 * employee, what pushes it up most, and what holds it down.
 */
export function riskSummary(score: RiskScore): string | null {
    if (score.explanation === null) {
        return null;
    }

    const baseline = Math.round(score.explanation.baseline);
    const up = score.factors.filter((f) => f.impact > 0).slice(0, 2);
    const down = score.factors.find((f) => f.impact < 0);
    const named = (f: RiskFactor) =>
        `${phrase(f)} (${formatPoints(factorPoints(f))})`;

    let sentence = `${Math.round(score.score)} against ${baseline} for a typical employee`;

    if (up.length > 0) {
        sentence += ` — raised most by ${joinWords(up.map(named))}`;
    } else if (score.factors.length === 0) {
        sentence += ' — nothing on record sets this person apart';
    }

    sentence += '.';

    if (down) {
        const held = named(down);
        sentence += ` ${held.charAt(0).toUpperCase()}${held.slice(1)} ${up.length > 0 ? 'holds it down' : 'lowers it'}.`;
    }

    return sentence;
}

/** For the list: the strongest reason pushing risk up, with its points. */
export function strongestReason(score: RiskScore): string | null {
    const top = score.factors.find((f) => f.direction === 'up');

    if (!top) {
        return null;
    }

    return score.explanation
        ? `${inputLabel(top)} ${formatPoints(factorPoints(top))}`
        : `${inputLabel(top)} ↑`;
}

/** Across a run: for each input, how many people it pushes up and down. */
export function driverRows(scores: RiskScore[]): DriverRow[] {
    const rows = new Map<
        string,
        DriverRow & { upSum: number; downSum: number }
    >();

    for (const score of scores) {
        for (const factor of score.factors) {
            const row = rows.get(factor.feature) ?? {
                key: factor.feature,
                label: inputLabel(factor),
                up: 0,
                down: 0,
                upPoints: 0,
                downPoints: 0,
                upSum: 0,
                downSum: 0,
            };
            const points = factorPoints(factor);

            if (points >= 0) {
                row.up += 1;
                row.upSum += points;
            } else {
                row.down += 1;
                row.downSum += points;
            }

            rows.set(factor.feature, row);
        }
    }

    return [...rows.values()]
        .map(({ upSum, downSum, ...row }) => ({
            ...row,
            upPoints: row.up > 0 ? upSum / row.up : 0,
            downPoints: row.down > 0 ? downSum / row.down : 0,
        }))
        .sort((a, b) => b.up + b.down - (a.up + a.down));
}

/** Risk by department, highest average first. */
export function departmentRows(scores: RiskScore[]) {
    return tallyBy(
        scores.map((s) => ({
            group: s.employee?.department ?? null,
            key: s.tier,
            score: s.score,
        })),
        ['high', 'medium', 'low'] as const,
    );
}
