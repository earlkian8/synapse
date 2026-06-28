import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, Download, Printer } from 'lucide-react';
import { useCallback } from 'react';
import { Button } from '@/components/ui/button';
import { ReportFilters } from '@/features/reports/components/report-filters';
import { ReportPagination } from '@/features/reports/components/report-pagination';
import { ReportTable } from '@/features/reports/components/report-table';
import { exportUrl } from '@/features/reports/lib';
import type { ReportShowProps } from '@/features/reports/types';

/**
 * The report runner: one screen for every report. It reflects the report's declared
 * filters, runs the server query, and renders a dense, paginated, exportable table.
 * Every control patches the query and re-fetches, so the URL is always a shareable,
 * reproducible snapshot of exactly what's on screen — and the CSV/print mirror it.
 */
export default function ReportShow() {
    const { report, applied, rows, summary, meta } =
        usePage<ReportShowProps>().props;

    const go = useCallback(
        (overrides: Record<string, string | number>) => {
            router.get(
                `/reports/${report.key}`,
                { ...applied, per_page: meta.per_page, ...overrides },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [report.key, applied, meta.per_page],
    );

    const onFilter = useCallback(
        (patch: Record<string, string>) => go({ ...patch, page: 1 }),
        [go],
    );

    const onReset = useCallback(() => {
        router.get(
            `/reports/${report.key}`,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, [report.key]);

    const generatedAt = new Date().toLocaleString();

    return (
        <>
            <Head title={`${report.name} — Reports`} />

            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                {/* Header */}
                <div className="report-no-print flex flex-wrap items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <Link
                            href="/reports"
                            className="flex w-fit items-center gap-1 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                        >
                            <ChevronLeft className="size-3.5" />
                            All reports
                        </Link>
                        <h1 className="text-xl font-semibold tracking-tight">
                            {report.name}
                        </h1>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            {report.description}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => window.print()}
                        >
                            <Printer className="size-4" />
                            Print
                        </Button>
                        <Button asChild size="sm">
                            <a href={exportUrl(report.key, applied)} download>
                                <Download className="size-4" />
                                Export CSV
                            </a>
                        </Button>
                    </div>
                </div>

                <div id="report-print" className="flex flex-col gap-4">
                    {/* Print-only masthead — makes the printout a self-describing artifact. */}
                    <div className="hidden print:block">
                        <h2 className="text-lg font-semibold">{report.name}</h2>
                        <p className="text-xs text-muted-foreground">
                            {report.description} · Generated {generatedAt}
                        </p>
                    </div>

                    <ReportFilters
                        filters={report.filters}
                        applied={applied}
                        onChange={onFilter}
                        onReset={onReset}
                    />

                    {summary.length > 0 && (
                        <div className="flex flex-wrap items-center gap-x-8 gap-y-2 rounded-lg border border-sidebar-border/70 bg-muted/30 px-4 py-2.5 dark:border-sidebar-border">
                            {summary.map((stat) => (
                                <div
                                    key={stat.label}
                                    className="flex items-baseline gap-2"
                                >
                                    <span className="text-[11px] tracking-wide text-muted-foreground uppercase">
                                        {stat.label}
                                    </span>
                                    <span className="text-sm font-semibold tracking-tight tabular-nums">
                                        {stat.value}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}

                    <ReportTable
                        columns={report.columns}
                        rows={rows}
                        from={meta.from || 1}
                    />

                    <ReportPagination
                        meta={meta}
                        onPage={(page) => go({ page })}
                        onPerPage={(perPage) =>
                            go({ per_page: perPage, page: 1 })
                        }
                    />
                </div>
            </div>

            <style>{`
                @media print {
                    body * { visibility: hidden; }
                    #report-print, #report-print * { visibility: visible; }
                    #report-print { position: absolute; left: 0; top: 0; width: 100%; }
                    .report-no-print { display: none !important; }
                }
            `}</style>
        </>
    );
}

ReportShow.layout = {
    breadcrumbs: [{ title: 'Reports', href: '/reports' }],
};
