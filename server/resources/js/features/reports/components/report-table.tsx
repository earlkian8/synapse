import { cn } from '@/lib/utils';
import { badgeTone, formatCell } from '../lib';
import type { ReportColumn, ReportRow } from '../types';

/**
 * The report grid: a dense, bordered, sticky-headed table built for scanning long
 * runs of records. Numeric columns are right-aligned and tabular; badge columns get a
 * tone-coded chip. Designed to read like an ERP register, not a marketing card.
 */
export function ReportTable({
    columns,
    rows,
    from,
}: {
    columns: ReportColumn[];
    rows: ReportRow[];
    from: number;
}) {
    if (rows.length === 0) {
        return (
            <div className="rounded-lg border border-dashed border-sidebar-border/70 bg-card/50 px-4 py-16 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                No rows match these filters.
            </div>
        );
    }

    return (
        <div className="overflow-x-auto rounded-lg border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
            <table className="w-full border-collapse text-[13px]">
                <thead>
                    <tr className="border-b border-border bg-muted/60">
                        <th className="sticky left-0 z-10 w-10 bg-muted/60 px-3 py-2 text-right text-[11px] font-semibold tracking-wide text-muted-foreground tabular-nums">
                            #
                        </th>
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                className={cn(
                                    'px-3 py-2 text-[11px] font-semibold tracking-wide whitespace-nowrap text-muted-foreground uppercase',
                                    column.align === 'right'
                                        ? 'text-right'
                                        : 'text-left',
                                )}
                            >
                                {column.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => (
                        <tr
                            key={index}
                            className="border-b border-border/60 last:border-0 odd:bg-transparent even:bg-muted/25 hover:bg-muted/50"
                        >
                            <td className="px-3 py-2 text-right text-xs text-muted-foreground/70 tabular-nums">
                                {from + index}
                            </td>
                            {columns.map((column) => (
                                <Cell
                                    key={column.key}
                                    column={column}
                                    row={row}
                                />
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function Cell({ column, row }: { column: ReportColumn; row: ReportRow }) {
    const raw = row[column.key];
    const value = formatCell(raw, column);

    if (column.type === 'badge' && value !== '—') {
        return (
            <td className="px-3 py-2 whitespace-nowrap">
                <span
                    className={cn(
                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                        badgeTone(value),
                    )}
                >
                    {value}
                </span>
            </td>
        );
    }

    return (
        <td
            className={cn(
                'px-3 py-2 whitespace-nowrap',
                column.align === 'right'
                    ? 'text-right tabular-nums'
                    : 'text-left',
                column.type === 'text' && column.key.includes('description')
                    ? 'max-w-md truncate whitespace-normal'
                    : '',
            )}
        >
            {value}
        </td>
    );
}
