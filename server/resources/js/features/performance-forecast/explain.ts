import { tallyBy } from '@/features/prediction-insights/lib';
import type { ChartGroup } from '@/features/prediction-insights/lib';
import { BAND_LABELS } from './constants';
import type { ForecastBand, ForecastRun, ForecastScore } from './types';

/** The bands as chart groups, Below → Exceeds. */
export const BAND_GROUPS: readonly ChartGroup<ForecastBand>[] = [
    {
        key: 'below',
        label: BAND_LABELS.below,
        fill: 'bg-amber-500',
        svgFill: 'fill-amber-500',
    },
    {
        key: 'on_track',
        label: BAND_LABELS.on_track,
        fill: 'bg-sky-500',
        svgFill: 'fill-sky-500',
    },
    {
        key: 'exceeds',
        label: BAND_LABELS.exceeds,
        fill: 'bg-emerald-500',
        svgFill: 'fill-emerald-500',
    },
];

/** Where the bands begin on the 0–100 scale. */
export function bandThresholds(
    run: ForecastRun,
): { value: number; label: string }[] {
    const bands = run.reference?.bands ?? { below: 60, on_track: 80 };

    return [
        { value: bands.below, label: BAND_LABELS.on_track },
        { value: bands.on_track, label: BAND_LABELS.exceeds },
    ];
}

/**
 * The forecast in words: the latest rating it reads, what next ratings after one
 * like it have been, and how far they strayed.
 */
export function forecastSummary(
    score: ForecastScore,
    scoredBy: 'own' | 'general',
): string | null {
    const latest = score.features.rating_latest;

    if (latest === undefined) {
        return null;
    }

    const drift = score.predicted_rating - latest;
    const where =
        scoredBy === 'own'
            ? 'in your organisation'
            : 'in the reference workforce';
    const move =
        Math.abs(drift) < 0.5
            ? 'about the same'
            : `${Math.abs(drift).toFixed(1)} points ${drift > 0 ? 'higher' : 'lower'}`;
    const range =
        score.predicted_low !== null && score.predicted_high !== null
            ? ` Four in five landed between ${Math.round(score.predicted_low)} and ${Math.round(score.predicted_high)}.`
            : '';

    return `The latest appraisal was ${latest.toFixed(1)}%. ${where.charAt(0).toUpperCase()}${where.slice(1)}, a rating like that was followed by ${Math.round(score.predicted_rating)} on average — ${move}.${range}`;
}

/** Forecasts by department, highest average first. */
export function departmentRows(forecasts: ForecastScore[]) {
    return tallyBy(
        forecasts.map((f) => ({
            group: f.employee?.department ?? null,
            key: f.band,
            score: f.predicted_rating,
        })),
        ['exceeds', 'on_track', 'below'] as const,
    );
}
