import { Scale } from 'lucide-react';
import { DataTable, EmptyTableRow, TableCard } from '@/components/data-table';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
    return (
        <TableCard
            title="Calibration by department"
            description="How each department rates against the cycle average"
            actions={
                average !== null && (
                    <span className="text-xs text-muted-foreground tabular-nums">
                        Cycle average {formatPercent(average)}
                    </span>
                )
            }
        >
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <TableHead>Department</TableHead>
                        <TableHead>Progress</TableHead>
                        <TableHead className="text-right">Average</TableHead>
                        <TableHead className="text-right">vs. cycle</TableHead>
                        <TableHead className="text-right">Top band</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.length === 0 && (
                        <EmptyTableRow
                            colSpan={5}
                            icon={Scale}
                            title="No departments to compare yet"
                            description="Calibration appears once appraisals are opened in this cycle."
                        />
                    )}

                    {rows.map((row) => {
                        const delta =
                            row.average_percent !== null && average !== null
                                ? row.average_percent - average
                                : null;

                        return (
                            <TableRow key={row.department}>
                                <TableCell className="max-w-56 truncate text-sm font-medium">
                                    {row.department}
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground tabular-nums">
                                    {row.completed} of {row.total} done
                                </TableCell>
                                <TableCell className="text-right text-sm tabular-nums">
                                    {formatPercent(row.average_percent)}
                                </TableCell>
                                <TableCell
                                    className={cn(
                                        'text-right text-sm font-medium tabular-nums',
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
                                </TableCell>
                                <TableCell className="text-right text-sm text-muted-foreground tabular-nums">
                                    {row.top_band_share === null
                                        ? '—'
                                        : `${row.top_band_share}%`}
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}
