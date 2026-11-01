import {
    ChartCard,
    ChartLegend,
    ChartTable,
} from '@/features/prediction-insights/components/chart-card';
import { GroupBreakdown } from '@/features/prediction-insights/components/group-breakdown';
import { ScoreHistogram } from '@/features/prediction-insights/components/score-histogram';
import { BAND_LABELS, formatRating } from '../constants';
import { BAND_GROUPS, bandThresholds, departmentRows } from '../explain';
import type { ForecastRun } from '../types';

/**
 * The run as a whole: how the forecasts spread across the bands, and by department.
 * Each chart has its numbers as a table.
 */
export function ForecastOverview({ run }: { run: ForecastRun }) {
    const departments = departmentRows(run.forecasts);
    const bestFirst = [...BAND_GROUPS].reverse();
    const counts = {
        exceeds: run.exceeds_count,
        on_track: run.on_track_count,
        below: run.below_count,
    };

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <ChartCard
                title="Where next ratings are expected"
                description="Each column is everyone forecast within five points of each other."
                legend={
                    <ChartLegend
                        items={bestFirst.map((g) => ({
                            key: g.key,
                            label: g.label,
                            fill: g.fill,
                            count: counts[g.key],
                        }))}
                    />
                }
                table={
                    <ChartTable
                        head={['Forecast', 'People']}
                        rows={Array.from({ length: 20 }, (_, i) => {
                            const from = i * 5;
                            const n = run.forecasts.filter(
                                (f) =>
                                    f.predicted_rating >= from &&
                                    (f.predicted_rating < from + 5 ||
                                        (i === 19 &&
                                            f.predicted_rating <= 100)),
                            ).length;

                            return [`${from}–${from + 5}`, n];
                        }).filter((row) => row[1] !== 0)}
                    />
                }
            >
                <ScoreHistogram
                    height={220}
                    items={run.forecasts.map((f) => ({
                        score: f.predicted_rating,
                        group: f.band,
                    }))}
                    groups={BAND_GROUPS}
                    thresholds={bandThresholds(run)}
                    label="Number of employees at each forecast rating"
                />
            </ChartCard>

            <ChartCard
                title="Forecasts by department"
                description="Each department’s people by band, highest average forecast first."
                legend={
                    <ChartLegend
                        items={bestFirst.map((g) => ({
                            key: g.key,
                            label: g.label,
                            fill: g.fill,
                        }))}
                    />
                }
                table={
                    <ChartTable
                        head={[
                            'Department',
                            'People',
                            ...bestFirst.map((g) => BAND_LABELS[g.key]),
                            'Average',
                        ]}
                        rows={departments.map((d) => [
                            d.label,
                            d.count,
                            d.segments.exceeds,
                            d.segments.on_track,
                            d.segments.below,
                            formatRating(d.average),
                        ])}
                    />
                }
            >
                <GroupBreakdown
                    rows={departments}
                    groups={bestFirst}
                    averageLabel="Avg forecast"
                />
            </ChartCard>
        </div>
    );
}
