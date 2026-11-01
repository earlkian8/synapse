import { BarChart3, Table2 } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The frame every overview chart sits in: a title that says what is plotted, a line
 * on how to read it, an optional legend, and a switch to the same numbers as a
 * table — so no value is only reachable by hovering or by telling colours apart.
 */
export function ChartCard({
    title,
    description,
    legend,
    table,
    children,
    className,
}: {
    title: string;
    description?: ReactNode;
    legend?: ReactNode;
    /** The chart's numbers as a table (the accessible twin). */
    table: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    const [view, setView] = useState<'chart' | 'table'>('chart');
    const titleId = useId();

    return (
        <section
            aria-labelledby={titleId}
            className={cn(
                'flex min-w-0 flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border',
                className,
            )}
        >
            <header className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h2 id={titleId} className="text-sm font-semibold">
                        {title}
                    </h2>
                    {description && (
                        <p className="text-xs text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                <div
                    role="group"
                    aria-label={`Show ${title.toLowerCase()} as`}
                    className="flex shrink-0 items-center rounded-md border border-sidebar-border/70 p-0.5 dark:border-sidebar-border"
                >
                    {(
                        [
                            ['chart', BarChart3, 'Chart'],
                            ['table', Table2, 'Table'],
                        ] as const
                    ).map(([key, Icon, label]) => (
                        <button
                            key={key}
                            type="button"
                            aria-pressed={view === key}
                            onClick={() => setView(key)}
                            className={cn(
                                'flex items-center gap-1 rounded px-2 py-1 text-[11px] font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                view === key && 'bg-muted text-foreground',
                            )}
                        >
                            <Icon className="size-3" />
                            {label}
                        </button>
                    ))}
                </div>
            </header>

            {view === 'chart' ? (
                <>
                    {children}
                    {legend}
                </>
            ) : (
                <div className="max-h-80 overflow-auto">{table}</div>
            )}
        </section>
    );
}

/** A plain, compact table for a chart's numbers. */
export function ChartTable({
    head,
    rows,
}: {
    head: string[];
    rows: (string | number)[][];
}) {
    return (
        <table className="w-full text-left text-xs">
            <thead className="sticky top-0 bg-card text-muted-foreground">
                <tr>
                    {head.map((cell, i) => (
                        <th
                            key={cell}
                            scope="col"
                            className={cn(
                                'border-b border-border py-1.5 font-medium',
                                i > 0 && 'text-right',
                            )}
                        >
                            {cell}
                        </th>
                    ))}
                </tr>
            </thead>
            <tbody>
                {rows.map((row) => (
                    <tr
                        key={String(row[0])}
                        className="border-b border-border/60 last:border-0"
                    >
                        {row.map((cell, i) => (
                            <td
                                key={i}
                                className={cn(
                                    'py-1.5',
                                    i > 0 && 'text-right tabular-nums',
                                )}
                            >
                                {cell}
                            </td>
                        ))}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

/** A legend: a swatch beside each label (identity never by colour alone). */
export function ChartLegend({
    items,
}: {
    items: { key: string; label: string; fill: string; count?: number }[];
}) {
    return (
        <ul className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
            {items.map((item) => (
                <li key={item.key} className="flex items-center gap-1.5">
                    <span className={cn('size-2.5 rounded-[3px]', item.fill)} />
                    {item.label}
                    {item.count !== undefined && (
                        <span className="font-medium text-foreground tabular-nums">
                            {item.count}
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}
