import { Scale } from 'lucide-react';
import { cn } from '@/lib/utils';
import { formatPercent } from '../constants';
import type { DepartmentCalibration } from '../types';

type Props = {
    rows: DepartmentCalibration[];
    /** The cycle-wide average, the line each department is read against. */
    average: number | null;
};

/**
 * Per-department calibration: whether one part of the company is rating softer
 * or harder than the rest.
 *
 * Each department's average attainment is shown as a deviation from the cycle
 * average, because the absolute number tells you nothing on its own — a company
 * that averages 82 is not generous, it just uses its scale differently. The
 * deviation is the thing HR acts on.
 */
export function CalibrationTable({ rows, average }: Props) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <section className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
            <header className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3">
                <div className="flex items-center gap-2.5">
                    <span className="flex size-7 items-center justify-center rounded-lg bg-[#0F2044]/8 text-[#0F2044] dark:bg-white/10 dark:text-white">
                        <Scale className="size-4" />
                    </span>
                    <div>
                        <h2 className="text-sm font-semibold">
                            Calibration by department
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            How each department rates against the cycle average
                        </p>
                    </div>
                </div>
                {average !== null && (
                    <span className="text-xs text-muted-foreground tabular-nums">
                        Cycle average {formatPercent(average)}
                    </span>
                )}
            </header>

            <div className="overflow-x-auto">
                <table className="w-full min-w-[38rem] text-sm">
                    <thead>
                        <tr className="border-b border-border text-left text-xs text-muted-foreground">
                            <th scope="col" className="px-4 py-2 font-medium">
                                Department
                            </th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                Progress
                            </th>
                            <th
                                scope="col"
                                className="px-4 py-2 text-right font-medium"
                            >
                                Average
                            </th>
                            <th
                                scope="col"
                                className="px-4 py-2 text-right font-medium"
                            >
                                vs. cycle
                            </th>
                            <th
                                scope="col"
                                className="px-4 py-2 text-right font-medium"
                            >
                                Top band
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        {rows.map((row) => {
                            const delta =
                                row.average_percent !== null && average !== null
                                    ? row.average_percent - average
                                    : null;

                            return (
                                <tr key={row.department}>
                                    <th
                                        scope="row"
                                        className="max-w-[14rem] truncate px-4 py-2.5 text-left font-medium"
                                    >
                                        {row.department}
                                    </th>
                                    <td className="px-4 py-2.5 text-muted-foreground tabular-nums">
                                        {row.completed} of {row.total} done
                                    </td>
                                    <td className="px-4 py-2.5 text-right tabular-nums">
                                        {formatPercent(row.average_percent)}
                                    </td>
                                    <td
                                        className={cn(
                                            'px-4 py-2.5 text-right font-medium tabular-nums',
                                            delta === null
                                                ? 'text-muted-foreground'
                                                : delta >= 5
                                                  ? 'text-emerald-600 dark:text-emerald-400'
                                                  : delta <= -5
                                                    ? 'text-rose-600 dark:text-rose-400'
                                                    : 'text-muted-foreground',
                                        )}
                                    >
                                        {delta === null
                                            ? '—'
                                            : `${delta > 0 ? '+' : ''}${delta.toFixed(1)}`}
                                    </td>
                                    <td className="px-4 py-2.5 text-right text-muted-foreground tabular-nums">
                                        {row.top_band_share === null
                                            ? '—'
                                            : `${row.top_band_share}%`}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
