import { Head, usePage } from '@inertiajs/react';
import { Scale } from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    PageBody,
    PageHeader,
    rowOpens,
    TableCard,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    GravityMark,
    StatusBadge,
} from '@/features/discipline/components/case-badges';
import { ConductRecordCard } from '@/features/discipline/components/conduct-record-card';
import { calendarDate, PENALTY_LABELS } from '@/features/discipline/constants';
import { disciplineRoutes } from '@/features/discipline/routes';
import type {
    CasePerson,
    CaseSummary,
    ConductRecord,
} from '@/features/discipline/types';

type Props = {
    employee: CasePerson;
    record: ConductRecord;
    cases: CaseSummary[];
};

/**
 * An employee's conduct record (ADR 0105): where they stand, what still counts
 * against them, and every case — reported, served or decided.
 */
export default function ConductRecordPage() {
    const { employee, record, cases } = usePage<Props>().props;

    return (
        <>
            <Head title={`${employee.name}: conduct record`} />

            <PageBody>
                <PageHeader
                    title={employee.name}
                    description={[
                        employee.employee_no,
                        employee.position,
                        employee.department,
                    ]
                        .filter(Boolean)
                        .join(', ')}
                    leading={
                        <PersonAvatar
                            name={employee.name}
                            initials={employee.initials}
                            photo={employee.photo}
                            className="size-12"
                        />
                    }
                />

                <ConductRecordCard
                    record={record}
                    caseHref={disciplineRoutes.show}
                />

                <TableCard title="Every case" count={cases.length}>
                    <DataTable>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Case</TableHead>
                                <TableHead>Incident</TableHead>
                                <TableHead>Where it stands</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {cases.length === 0 && (
                                <EmptyTableRow
                                    colSpan={3}
                                    icon={Scale}
                                    title="No cases"
                                    description="Nothing has been reported about them."
                                />
                            )}
                            {cases.map((item) => (
                                <TableRow
                                    key={item.hashid}
                                    {...rowOpens(
                                        disciplineRoutes.show(item.hashid),
                                    )}
                                >
                                    <TableCell>
                                        <span className="flex items-center gap-2">
                                            {item.offense && (
                                                <GravityMark
                                                    gravity={
                                                        item.offense.gravity
                                                    }
                                                />
                                            )}
                                            <span className="font-medium">
                                                {item.offense?.title ??
                                                    'No offense set'}
                                            </span>
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {item.reference}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-sm whitespace-nowrap">
                                        {calendarDate(item.incident_date)}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge status={item.status} />
                                        {item.finding && (
                                            <span className="mt-0.5 block text-xs text-muted-foreground">
                                                {item.finding === 'upheld' &&
                                                item.penalty
                                                    ? PENALTY_LABELS[
                                                          item.penalty
                                                      ]
                                                    : 'Not upheld'}
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </DataTable>
                </TableCard>
            </PageBody>
        </>
    );
}

ConductRecordPage.layout = {
    breadcrumbs: [
        { title: 'Discipline', href: disciplineRoutes.index },
        { title: 'Conduct record', href: '#' },
    ],
};
