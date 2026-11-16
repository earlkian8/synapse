import { Head, router, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    Clock,
    FilePlus2,
    FileWarning,
    Hourglass,
    Inbox,
    Scale,
} from 'lucide-react';
import { useState } from 'react';
import {
    DataTable,
    EmptyTableRow,
    ListToolbar,
    PageBody,
    PageHeader,
    rowOpens,
    SearchInput,
    StatTiles,
    TableCard,
    TablePagination,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
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
import { DisciplineNav } from '@/features/discipline/components/discipline-nav';
import { ReportIncidentDialog } from '@/features/discipline/components/report-incident-dialog';
import { calendarDate, PENALTY_LABELS } from '@/features/discipline/constants';
import { disciplineRoutes } from '@/features/discipline/routes';
import type { CaseSummary, CasesPageProps } from '@/features/discipline/types';
import { cn } from '@/lib/utils';

const VIEWS = [
    { key: 'open', label: 'Open' },
    { key: 'decided', label: 'Decided' },
    { key: 'closed', label: 'Closed' },
] as const;

/**
 * Discipline → Cases (ADR 0105): every case, the open ones first, with what is
 * waiting on whom — a report to read, an answer due, a hearing to hold.
 */
export default function DisciplineCases() {
    const { cases, filters, counts, stats, options, can } =
        usePage<CasesPageProps>().props;
    const [reporting, setReporting] = useState(false);

    const visit = (params: Record<string, string | number | undefined>) =>
        router.get(
            disciplineRoutes.cases,
            { ...filters, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Discipline" />

            <PageBody>
                <PageHeader
                    title="Discipline"
                    description="Incidents taken through due process: a notice to explain, the employee’s answer, a hearing when one is asked for, and a written decision."
                    actions={<DisciplineNav current="cases" />}
                />

                <StatTiles
                    tiles={[
                        {
                            key: 'reported',
                            label: 'Reports to read',
                            value: stats.reported,
                            icon: Inbox,
                            accent: 'sky',
                        },
                        {
                            key: 'awaiting',
                            label: 'Answers due',
                            value: stats.awaiting,
                            icon: Hourglass,
                            accent: 'amber',
                        },
                        {
                            key: 'overdue',
                            label: 'Not answered in time',
                            value: stats.overdue,
                            icon: Clock,
                            accent: stats.overdue > 0 ? 'rose' : 'slate',
                            valueClassName:
                                stats.overdue > 0
                                    ? 'text-rose-600 dark:text-rose-400'
                                    : undefined,
                        },
                        {
                            key: 'hearings',
                            label: 'Hearings to hold',
                            value: stats.hearings,
                            icon: CalendarClock,
                            accent: 'indigo',
                        },
                    ]}
                />

                <TableCard
                    title="Cases"
                    count={cases.meta.total}
                    actions={
                        can.report ? (
                            <Button
                                size="sm"
                                onClick={() => setReporting(true)}
                            >
                                <FilePlus2 className="size-4" />
                                Report an incident
                            </Button>
                        ) : undefined
                    }
                >
                    <div className="space-y-3 border-b px-4 py-3">
                        <div
                            role="tablist"
                            aria-label="Which cases"
                            className="flex gap-4 overflow-x-auto border-b"
                        >
                            {VIEWS.map((view) => (
                                <button
                                    key={view.key}
                                    role="tab"
                                    type="button"
                                    aria-selected={filters.view === view.key}
                                    onClick={() =>
                                        visit({
                                            view: view.key,
                                            page: undefined,
                                        })
                                    }
                                    className={cn(
                                        'border-b-2 pb-2 text-sm font-medium whitespace-nowrap transition-colors',
                                        filters.view === view.key
                                            ? 'border-[#0ABFBF] text-foreground'
                                            : 'border-transparent text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {view.label}
                                    <span className="ml-1.5 text-xs text-muted-foreground tabular-nums">
                                        {counts[view.key]}
                                    </span>
                                </button>
                            ))}
                        </div>
                        <ListToolbar
                            filtered={filters.search !== ''}
                            onReset={() => visit({ search: '' })}
                        >
                            <SearchInput
                                value={filters.search}
                                onSearch={(search) =>
                                    visit({ search, page: undefined })
                                }
                                placeholder="Search a name, number or DC reference"
                                label="Search cases"
                            />
                        </ListToolbar>
                    </div>
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
                            {cases.data.length === 0 && (
                                <EmptyTableRow
                                    colSpan={4}
                                    icon={
                                        filters.view === 'open'
                                            ? Scale
                                            : FileWarning
                                    }
                                    title={
                                        filters.search
                                            ? 'No case matches'
                                            : filters.view === 'open'
                                              ? 'No open cases'
                                              : 'Nothing here yet'
                                    }
                                    description={
                                        filters.view === 'open'
                                            ? 'Incidents reported by supervisors and HR show up here to be read.'
                                            : undefined
                                    }
                                />
                            )}
                            {cases.data.map((item) => (
                                <CaseRow key={item.hashid} item={item} />
                            ))}
                        </TableBody>
                    </DataTable>
                    {cases.meta.last_page > 1 && (
                        <div className="border-t px-4 py-3">
                            <TablePagination
                                meta={cases.meta}
                                perPage={cases.meta.per_page}
                                onPage={(page) => visit({ page })}
                                onPerPage={(per_page) =>
                                    visit({ per_page, page: undefined })
                                }
                            />
                        </div>
                    )}
                </TableCard>
            </PageBody>

            <ReportIncidentDialog
                open={reporting}
                onOpenChange={setReporting}
                options={options}
            />
        </>
    );
}

function CaseRow({ item }: { item: CaseSummary }) {
    const open = ['reported', 'notice_issued', 'explained', 'hearing'].includes(
        item.status,
    );

    return (
        <TableRow {...rowOpens(disciplineRoutes.show(item.hashid))}>
            <TableCell className="max-w-72">
                <div className="flex items-center gap-2.5">
                    <PersonAvatar
                        name={item.employee?.name ?? ''}
                        initials={item.employee?.initials ?? '?'}
                        photo={item.employee?.photo ?? null}
                        className="size-8"
                    />
                    <div className="min-w-0">
                        <p className="truncate font-medium">
                            {item.employee?.name}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {item.reference}
                            {item.employee?.department
                                ? `, ${item.employee.department}`
                                : ''}
                        </p>
                    </div>
                </div>
            </TableCell>
            <TableCell className="max-w-64">
                {item.offense ? (
                    <span className="flex items-center gap-2">
                        <GravityMark gravity={item.offense.gravity} />
                        <span className="truncate text-sm">
                            {item.offense.title}
                        </span>
                    </span>
                ) : (
                    <span className="text-sm text-muted-foreground">
                        Not set yet
                    </span>
                )}
            </TableCell>
            <TableCell className="text-sm whitespace-nowrap">
                {calendarDate(item.incident_date)}
            </TableCell>
            <TableCell>
                <StatusBadge
                    status={item.status}
                    overdue={open && item.reply_overdue}
                />
                <span className="mt-0.5 block text-xs text-muted-foreground">
                    {item.status === 'notice_issued' &&
                    !item.reply_overdue &&
                    item.reply_due_on
                        ? `Answer due ${calendarDate(item.reply_due_on)}`
                        : item.finding === 'upheld' && item.penalty
                          ? PENALTY_LABELS[item.penalty]
                          : item.finding === 'cleared'
                            ? 'Not upheld'
                            : ''}
                </span>
            </TableCell>
        </TableRow>
    );
}

DisciplineCases.layout = {
    breadcrumbs: [{ title: 'Discipline', href: disciplineRoutes.index }],
};
