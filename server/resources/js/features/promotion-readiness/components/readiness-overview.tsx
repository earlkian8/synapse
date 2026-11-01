import {
    ChartCard,
    ChartLegend,
    ChartTable,
} from '@/features/prediction-insights/components/chart-card';
import { GroupBreakdown } from '@/features/prediction-insights/components/group-breakdown';
import { ScoreHistogram } from '@/features/prediction-insights/components/score-histogram';
import { formatChange, formatScore, TIER_LABELS } from '../constants';
import {
    departmentRows,
    READINESS_GROUPS,
    readinessThresholds,
} from '../explain';
import type { ReadinessRun, ReadinessScore } from '../types';
import { AppraisalMap } from './appraisal-map';

/**
 * The run as a whole: how readiness is spread, everyone on the appraisal map the
 * model reads, and readiness by department. Each chart has its numbers as a table.
 */
export function ReadinessOverview({
    run,
    onOpen,
}: {
    run: ReadinessRun;
    onOpen: (score: ReadinessScore) => void;
}) {
    const departments = departmentRows(run.scores);
    const bestFirst = [...READINESS_GROUPS].reverse();
    const counts = {
        high: run.high_count,
        medium: run.medium_count,
        low: run.low_count,
    };

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <ChartCard
                title="How readiness is spread"
                description={
                    run.reference
                        ? `Each column is everyone within five points. Medium starts at ${Math.round(run.reference.tier_scores.medium)}, High at ${Math.round(run.reference.tier_scores.high)}.`
                        : 'Each column is everyone within five points.'
                }
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
                        head={['Readiness', 'People']}
                        rows={Array.from({ length: 20 }, (_, i) => {
                            const from = i * 5;
                            const n = run.scores.filter(
                                (s) =>
                                    s.score >= from &&
                                    (s.score < from + 5 ||
                                        (i === 19 && s.score <= 100)),
                            ).length;

                            return [`${from}–${from + 5}`, n];
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
                    groups={READINESS_GROUPS}
                    thresholds={readinessThresholds(run)}
                    label="Number of employees at each readiness score"
                />
            </ChartCard>

            <ChartCard
                title="The appraisal map"
                description="Everyone by latest appraisal and how much it changed. Readiness rises to the upper right; select a dot to see why."
                table={
                    <ChartTable
                        head={[
                            'Employee',
                            'Latest',
                            'Change',
                            'Readiness',
                            'Tier',
                        ]}
                        rows={run.scores.map((s) => [
                            s.employee?.full_name ?? '—',
                            s.features.rating_latest !== undefined
                                ? `${s.features.rating_latest.toFixed(1)}%`
                                : '—',
                            s.features.rating_change !== undefined
                                ? formatChange(s.features.rating_change)
                                : 'one appraisal',
                            formatScore(s.score),
                            TIER_LABELS[s.tier],
                        ])}
                    />
                }
            >
                <AppraisalMap
                    scores={run.scores}
                    reference={run.reference}
                    onOpen={onOpen}
                />
            </ChartCard>

            <ChartCard
                className="lg:col-span-2"
                title="Readiness by department"
                description="Each department’s people by tier, highest average readiness first."
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
                            'High',
                            'Medium',
                            'Low',
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
                    groups={bestFirst}
                    averageLabel="Avg readiness"
                />
            </ChartCard>
        </div>
    );
}
