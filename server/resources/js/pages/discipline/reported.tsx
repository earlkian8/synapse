import { Head, Link, usePage } from '@inertiajs/react';
import { FilePlus2, FileWarning } from 'lucide-react';
import { useState } from 'react';
import {
    DataTable,
    EmptyTableRow,
    PageBody,
    PageHeader,
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
import { StatusBadge } from '@/features/discipline/components/case-badges';
import { DisciplineNav } from '@/features/discipline/components/discipline-nav';
import { ReportIncidentDialog } from '@/features/discipline/components/report-incident-dialog';
import { calendarDate } from '@/features/discipline/constants';
import { disciplineRoutes } from '@/features/discipline/routes';
import type { CaseStatus, ReportOptions } from '@/features/discipline/types';

type Props = {
    reports: {
        reference: string;
        employee: string | null;
        offense: string | null;
        incident_date: string;
        status: CaseStatus;
        reported_at: string | null;
    }[];
    options: ReportOptions;
    can: { view: boolean };
};

/**
 * Reported by me (ADR 0105): the incidents somebody filed and how far each got
 * — no more. The explanation and the reasons for a decision stay with HR.
 */
export default function ReportedByMe() {
    const { reports, options, can } = usePage<Props>().props;
    const [reporting, setReporting] = useState(false);

    return (
        <>
            <Head title="Reported by me" />

            <PageBody>
                <PageHeader
                    title="Reported by me"
                    description="Incidents you reported about somebody, and how far HR has taken each. What the employee answers and why a case was decided stay with HR."
                    actions={<DisciplineNav current="reported" />}
                />

                <TableCard
                    title="Your reports"
                    count={reports.length}
                    actions={
                        <Button size="sm" onClick={() => setReporting(true)}>
                            <FilePlus2 className="size-4" />
                            Report an incident
                        </Button>
                    }
                >
                    <DataTable>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Employee</TableHead>
                                <TableHead>Offense</TableHead>
                                <TableHead>Incident</TableHead>
                                <TableHead>Where it stands</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {reports.length === 0 && (
                                <EmptyTableRow
                                    colSpan={4}
                                    icon={FileWarning}
                                    title="You haven’t reported anything"
                                    description="When somebody on your team breaks a rule, report it here with the facts. HR decides whether to charge."
                                />
                            )}
                            {reports.map((report) => (
                                <TableRow key={report.reference}>
                                    <TableCell>
                                        <p className="font-medium">
                                            {report.employee}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {report.reference}
                                        </p>
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {report.offense ?? (
                                            <span className="text-muted-foreground">
                                                Not set
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-sm whitespace-nowrap">
                                        {calendarDate(report.incident_date)}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge status={report.status} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </DataTable>
                </TableCard>

                {can.view && (
                    <p className="text-center text-sm text-muted-foreground">
                        Every case, not only yours, is under{' '}
                        <Link
                            href={disciplineRoutes.cases}
                            className="font-medium text-foreground underline-offset-2 hover:underline"
                        >
                            Cases
                        </Link>
                        .
                    </p>
                )}
            </PageBody>

            <ReportIncidentDialog
                open={reporting}
                onOpenChange={setReporting}
                options={options}
            />
        </>
    );
}

ReportedByMe.layout = {
    breadcrumbs: [
        { title: 'Discipline', href: disciplineRoutes.index },
        { title: 'Reported by me', href: disciplineRoutes.reported },
    ],
};
