import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, FileSpreadsheet } from 'lucide-react';
import { groupIcon } from '@/features/reports/lib';
import type {
    ReportListItem,
    ReportsIndexProps,
} from '@/features/reports/types';

/**
 * The report hub: every report the signed-in user may run, grouped by section. Built
 * to read like an ERP report catalogue — tight rows, scannable, no marketing fluff.
 */
export default function ReportsIndex() {
    const { reports } = usePage<ReportsIndexProps>().props;

    const groups = groupReports(reports);

    return (
        <>
            <Head title="Reports" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-col gap-1">
                    <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
                        <FileSpreadsheet className="size-5 text-[#0ABFBF]" />
                        Reports
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Parameterised, exportable views over your organisation's
                        records — for auditing, reconciliation and compliance.
                        Each report is filterable and downloads to CSV exactly
                        as shown.
                    </p>
                </header>

                {reports.length === 0 ? (
                    <p className="rounded-lg border border-dashed border-sidebar-border/70 bg-card/50 px-4 py-16 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                        You don't have access to any reports.
                    </p>
                ) : (
                    groups.map(([group, items]) => {
                        const Icon = groupIcon(group);

                        return (
                            <section
                                key={group}
                                className="flex flex-col gap-3"
                            >
                                <h2 className="flex items-center gap-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    <Icon className="size-4" />
                                    {group}
                                </h2>
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                    {items.map((report) => (
                                        <ReportCard
                                            key={report.key}
                                            report={report}
                                        />
                                    ))}
                                </div>
                            </section>
                        );
                    })
                )}
            </div>
        </>
    );
}

function ReportCard({ report }: { report: ReportListItem }) {
    return (
        <Link
            href={`/reports/${report.key}`}
            className="group flex flex-col gap-1.5 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm transition-colors hover:border-[#0ABFBF]/50 hover:bg-muted/30 dark:border-sidebar-border"
        >
            <div className="flex items-center justify-between">
                <h3 className="text-sm font-semibold tracking-tight">
                    {report.name}
                </h3>
                <ArrowRight className="size-4 text-muted-foreground/50 transition-all group-hover:translate-x-0.5 group-hover:text-[#0ABFBF]" />
            </div>
            <p className="text-xs leading-relaxed text-muted-foreground">
                {report.description}
            </p>
        </Link>
    );
}

/** Preserve the server's report order while collecting them into sections. */
function groupReports(reports: ReportListItem[]): [string, ReportListItem[]][] {
    const map = new Map<string, ReportListItem[]>();

    for (const report of reports) {
        const bucket = map.get(report.group) ?? [];
        bucket.push(report);
        map.set(report.group, bucket);
    }

    return Array.from(map.entries());
}

ReportsIndex.layout = {
    breadcrumbs: [{ title: 'Reports', href: '/reports' }],
};
