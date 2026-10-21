import { Head, usePage } from '@inertiajs/react';
import { MessageSquareReply, MessageSquareWarning } from 'lucide-react';
import { useState } from 'react';
import {
    DataTable,
    EmptyTableRow,
    PageBody,
    PageHeader,
    rowOpens,
    TableCard,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ReportDetailDialog } from '@/features/problem-reports/components/report-detail-dialog';
import { ReportStatusPill } from '@/features/problem-reports/components/report-status-pill';
import { formatDate } from '@/features/problem-reports/format';
import { problemReportLauncher } from '@/features/problem-reports/launcher';
import { problemReportRoutes } from '@/features/problem-reports/routes';
import type { ProblemReportsPageProps } from '@/features/problem-reports/types';

/**
 * Your reports (ADR 0096): every problem one has reported to the SYNAPSE team,
 * from the web or the app, where each stands, and the team's replies. Filed
 * from the Report a problem dialog, which this page's button opens too.
 */
export default function ProblemReportsPage() {
    const { reports, focus } = usePage<ProblemReportsPageProps>().props;
    const [openReference, setOpenReference] = useState<string | null>(focus);
    const open = reports.find((report) => report.reference === openReference);
    const waiting = reports.filter((report) => report.is_open).length;

    return (
        <>
            <Head title="Your reports" />

            <PageBody>
                <PageHeader
                    title="Your reports"
                    description="Problems you’ve reported to the SYNAPSE team, where each one stands, and their replies."
                    actions={
                        <Button onClick={() => problemReportLauncher.open()}>
                            <MessageSquareWarning />
                            Report a problem
                        </Button>
                    }
                />

                <TableCard
                    title="Reports"
                    count={reports.length}
                    description={
                        reports.length > 0
                            ? waiting === 0
                                ? 'None is waiting on the team.'
                                : `${waiting} waiting on the team.`
                            : undefined
                    }
                >
                    <DataTable>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Report</TableHead>
                                <TableHead className="hidden md:table-cell">
                                    Kind
                                </TableHead>
                                <TableHead className="hidden sm:table-cell">
                                    Sent
                                </TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {reports.length === 0 && (
                                <EmptyTableRow
                                    colSpan={4}
                                    icon={MessageSquareWarning}
                                    title="No reports yet"
                                    description="When something in SYNAPSE doesn’t work as it should, report it here or from Help in the top bar. You can follow it, and read the team’s reply, on this page."
                                />
                            )}
                            {reports.map((report) => (
                                <TableRow
                                    key={report.reference}
                                    {...rowOpens(() =>
                                        setOpenReference(report.reference),
                                    )}
                                >
                                    <TableCell className="max-w-0 whitespace-normal sm:max-w-96">
                                        <span className="line-clamp-2 font-medium sm:line-clamp-1">
                                            {report.subject}
                                        </span>
                                        <span className="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs whitespace-nowrap text-muted-foreground">
                                            <span className="tabular-nums">
                                                {report.reference}
                                            </span>
                                            <span className="sm:hidden">
                                                ·{' '}
                                                {formatDate(report.created_at)}
                                            </span>
                                            {report.reply && (
                                                <span className="inline-flex items-center gap-1 font-medium text-[#0A9E9E] dark:text-[#0ABFBF]">
                                                    <MessageSquareReply className="size-3.5" />
                                                    Replied
                                                </span>
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell className="hidden text-muted-foreground md:table-cell">
                                        {report.category_label}
                                    </TableCell>
                                    <TableCell className="hidden text-muted-foreground sm:table-cell">
                                        {formatDate(report.created_at)}
                                    </TableCell>
                                    <TableCell>
                                        <ReportStatusPill report={report} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </DataTable>
                </TableCard>
            </PageBody>

            <ReportDetailDialog
                report={open ?? null}
                onClose={() => setOpenReference(null)}
            />
        </>
    );
}

ProblemReportsPage.layout = {
    breadcrumbs: [
        { title: 'Help Center', href: '/help' },
        { title: 'Your reports', href: problemReportRoutes.index },
    ],
};
