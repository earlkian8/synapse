import { BarList, Donut, Legend } from '@/features/dashboard/components/charts';
import type { Segment } from '@/features/dashboard/components/charts';
import { ACCENT } from '@/features/dashboard/lib';
import type { ChartPoint, ChartSpec } from '../types';

/** A stable, readable palette for chart slices, reused across every report. */
const PALETTE = [
    ACCENT.teal,
    ACCENT.indigo,
    ACCENT.amber,
    ACCENT.slate,
    '#10B981',
    '#F43F5E',
    '#8B5CF6',
    '#0EA5E9',
];

/** The decision-view strip: a report's charts, derived from its whole result set. */
export function ReportCharts({ charts }: { charts: ChartSpec[] }) {
    if (charts.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            {charts.map((chart) => (
                <div
                    key={chart.title}
                    className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border"
                >
                    <h3 className="mb-3 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                        {chart.title}
                    </h3>
                    {chart.type === 'donut' ? (
                        <DonutChart points={chart.segments ?? []} />
                    ) : (
                        <BarsChart points={chart.bars ?? []} />
                    )}
                </div>
            ))}
        </div>
    );
}

function DonutChart({ points }: { points: ChartPoint[] }) {
    if (points.length === 0) {
        return <Empty />;
    }

    const segments: Segment[] = points.map((point, index) => ({
        label: point.label,
        value: point.value,
        color: PALETTE[index % PALETTE.length],
    }));
    const total = points.reduce((sum, point) => sum + point.value, 0);

    return (
        <div className="flex flex-wrap items-center gap-4">
            <Donut
                segments={segments}
                size={120}
                thickness={14}
                centerValue={total.toLocaleString()}
                centerLabel="total"
            />
            <div className="min-w-[130px] flex-1">
                <Legend segments={segments} />
            </div>
        </div>
    );
}

function BarsChart({ points }: { points: ChartPoint[] }) {
    if (points.length === 0) {
        return <Empty />;
    }

    return (
        <BarList
            bars={points.map((point) => ({
                label: point.label,
                value: point.value,
            }))}
        />
    );
}

function Empty() {
    return (
        <p className="py-6 text-center text-xs text-muted-foreground">
            No data in range.
        </p>
    );
}
