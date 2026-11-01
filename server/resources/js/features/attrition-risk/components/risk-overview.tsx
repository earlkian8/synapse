import {
    ChartCard,
    ChartLegend,
    ChartTable,
} from '@/features/prediction-insights/components/chart-card';
import { DriverChart } from '@/features/prediction-insights/components/driver-chart';
import { GroupBreakdown } from '@/features/prediction-insights/components/group-breakdown';
import { ScoreHistogram } from '@/features/prediction-insights/components/score-histogram';
import { formatPoints } from '@/features/prediction-insights/lib';
import { TIER_LABELS } from '../constants';
import {
    departmentRows,
    driverRows,
    RISK_GROUPS,
    riskThresholds,
} from '../explain';
import type { RiskRun } from '../types';

/**
 * The run as a whole: how risk is spread, what drives it across the workforce, and
 * where it concentrates by department. Each chart has its numbers as a table.
 */
export function RiskOverview({ run }: { run: RiskRun }) {
    const thresholds = riskThresholds(run);
    const drivers = driverRows(run.scores);
    const departments = departmentRows(run.scores);
    const counts = {
        high: run.high_count,
        medium: run.medium_count,
        low: run.low_count,
    };
    const urgentFirst = [...RISK_GROUPS].reverse();

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <ChartCard
                title="How risk is spread"
                description={
                    run.reference
                        ? `Each column is everyone within five points. A typical employee scores ${Math.round(run.reference.baseline)}.`
                        : 'Each column is everyone within five points.'
                }
                legend={
                    <ChartLegend
                        items={urgentFirst.map((g) => ({
                            key: g.key,
                            label: g.label,
                            fill: g.fill,
                            count: counts[g.key],
                        }))}
                    />
                }
                table={
                    <ChartTable
                        head={['Risk', 'People']}
                        rows={Array.from({ length: 20 }, (_, i) => {
                            const from = i * 5;
                            const to = from + 5;
                            const n = run.scores.filter(
                                (s) =>
                                    s.score >= from &&
                                    (s.score < to ||
                                        (i === 19 && s.score <= 100)),
                            ).length;

                            return [`${from}–${to}`, n];
                        }).filter((row) => row[1] !== 0)}
                    />
                }
            >
                <ScoreHistogram
                    height={220}
                    items={run.scores.map((s) => ({
                        score: s.score,
                        group: s.tier,
                    }))}
                    groups={RISK_GROUPS}
                    thresholds={thresholds}
                    label="Number of employees at each risk score"
                />
            </ChartCard>

            <ChartCard
                title="What drives risk"
                description="For each input, how many people it raises or lowers risk for, by two points or more."
                table={
                    <ChartTable
                        head={[
                            'Input',
                            'Raises',
                            'Avg pts',
                            'Lowers',
                            'Avg pts',
                        ]}
                        rows={drivers.map((d) => [
                            d.label,
                            d.up,
                            d.up ? formatPoints(d.upPoints) : '—',
                            d.down,
                            d.down ? formatPoints(d.downPoints) : '—',
                        ])}
                    />
                }
            >
                {drivers.length > 0 ? (
                    <DriverChart
                        rows={drivers}
                        upFavourable={false}
                        upLabel="Raises risk"
                        downLabel="Lowers risk"
                    />
                ) : (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        No input moved anyone’s risk by two points or more.
                    </p>
                )}
            </ChartCard>

            <ChartCard
                className="lg:col-span-2"
                title="Risk by department"
                description="Each department’s people by tier, highest average risk first."
                legend={
                    <ChartLegend
                        items={urgentFirst.map((g) => ({
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
                            ...urgentFirst.map((g) => TIER_LABELS[g.key]),
                            'Average',
                        ]}
                        rows={departments.map((d) => [
                            d.label,
                            d.count,
                            d.segments.high,
                            d.segments.medium,
                            d.segments.low,
                            Math.round(d.average),
                        ])}
                    />
                }
            >
                <GroupBreakdown
                    rows={departments}
                    groups={urgentFirst}
                    averageLabel="Avg risk"
                />
            </ChartCard>
        </div>
    );
}
