import { Head, router, usePage } from '@inertiajs/react';
import { Download, FileSpreadsheet, Printer } from 'lucide-react';
import { useCallback } from 'react';
import { Button } from '@/components/ui/button';
import { InsightsPanel } from '@/features/reports/components/insights-panel';
import { ReportCharts } from '@/features/reports/components/report-charts';
import { ReportFilters } from '@/features/reports/components/report-filters';
import { ReportPagination } from '@/features/reports/components/report-pagination';
import { ReportRail } from '@/features/reports/components/report-rail';
import { ReportSignals } from '@/features/reports/components/report-signals';
import { ReportTable } from '@/features/reports/components/report-table';
import { exportUrl } from '@/features/reports/lib';
import type {
    ActiveReport,
    ReportsWorkspaceProps,
} from '@/features/reports/types';

/**
 * The Reports analytics workspace: a single page with a report rail on the left and
 * the selected report rendered inline on the right — its decision-making charts, the
 * model signals, on-demand AI insights, totals and table. Switching reports and
 * changing filters are partial visits (`only: ['active']`), so the rail never reloads
 * and the URL stays a reproducible snapshot of the view.
 */
export default function ReportsWorkspace() {
    const { reports, active } = usePage<ReportsWorkspaceProps>().props;

    const reload = useCallback((params: Record<string, string | number>) => {
        router.get('/reports', params, {
            only: ['active'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, []);

    const selectReport = useCallback(
        (key: string) => reload({ report: key }),
        [reload],
    );

    const go = useCallback(
        (overrides: Record<string, string | number>) => {
            if (!active) {
                return;
            }

            reload({
                report: active.key,
                ...active.applied,
                per_page: active.meta.per_page,
                ...overrides,
            });
        },
        [active, reload],
    );

    return (
        <>
            <Head title="Reports" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <header className="flex flex-col gap-1">
                    <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
                        <FileSpreadsheet className="size-5 text-[#0ABFBF]" />
                        Reports
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Decision-making views across every module — charts,
                        model signals and AI analysis answering what's
                        happening, what changed and why, all exportable for
                        auditing.
                    </p>
                </header>

                {reports.length === 0 || !active ? (
                    <p className="rounded-lg border border-dashed border-sidebar-border/70 bg-card/50 px-4 py-16 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                        You don't have access to any reports.
                    </p>
                ) : (
                    <div className="flex flex-col gap-5 lg:flex-row lg:gap-6">
                        <aside className="lg:w-56 lg:shrink-0">
                            <ReportRail
                                reports={reports}
                                activeKey={active.key}
                                onSelect={selectReport}
                            />
                        </aside>
                        <div className="min-w-0 flex-1">
                            <ActiveReportView
                                active={active}
                                onFilter={(patch) => go({ ...patch, page: 1 })}
                                onReset={() => selectReport(active.key)}
                                onPage={(page) => go({ page })}
                                onPerPage={(perPage) =>
                                    go({ per_page: perPage, page: 1 })
                                }
                            />
                        </div>
                    </div>
                )}
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

function ActiveReportView({
    active,
    onFilter,
    onReset,
    onPage,
    onPerPage,
}: {
    active: ActiveReport;
    onFilter: (patch: Record<string, string>) => void;
    onReset: () => void;
    onPage: (page: number) => void;
    onPerPage: (perPage: number) => void;
}) {
    const generatedAt = new Date().toLocaleString();

    return (
        <div className="flex flex-col gap-4">
            {/* Header */}
            <div className="report-no-print flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-0.5">
                    <h2 className="text-lg font-semibold tracking-tight">
                        {active.name}
                    </h2>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {active.description}
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
                        <a
                            href={exportUrl(active.key, active.applied)}
                            download
                        >
                            <Download className="size-4" />
                            Export CSV
                        </a>
                    </Button>
                </div>
            </div>

            <ReportSignals signals={active.signals} />

            <InsightsPanel
                key={`${active.key}:${JSON.stringify(active.applied)}`}
                reportKey={active.key}
                applied={active.applied}
                aiEnabled={active.ai_enabled}
            />

            <div id="report-print" className="flex flex-col gap-4">
                {/* Print-only masthead — makes the printout self-describing. */}
                <div className="hidden print:block">
                    <h2 className="text-lg font-semibold">{active.name}</h2>
                    <p className="text-xs text-muted-foreground">
                        {active.description} · Generated {generatedAt}
                    </p>
                </div>

                <ReportCharts charts={active.charts} />

                <ReportFilters
                    filters={active.filters}
                    applied={active.applied}
                    onChange={onFilter}
                    onReset={onReset}
                />

                {active.summary.length > 0 && (
                    <div className="flex flex-wrap items-center gap-x-8 gap-y-2 rounded-lg border border-sidebar-border/70 bg-muted/30 px-4 py-2.5 dark:border-sidebar-border">
                        {active.summary.map((stat) => (
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
                    columns={active.columns}
                    rows={active.rows}
                    from={active.meta.from || 1}
                />

                <ReportPagination
                    meta={active.meta}
                    onPage={onPage}
                    onPerPage={onPerPage}
                />
            </div>
        </div>
    );
}

ReportsWorkspace.layout = {
    breadcrumbs: [{ title: 'Reports', href: '/reports' }],
};
