import { Head, usePage } from '@inertiajs/react';
import {
    CircleCheck,
    ClipboardList,
    Hourglass,
    TriangleAlert,
    Undo2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    DataTable,
    EmptyTableRow,
    FilterSelect,
    ListToolbar,
    PageBody,
    PageHeader,
    rowOpens,
    SearchInput,
    StatTiles,
    TableCard,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { OffboardingNav } from '@/features/offboarding/components/offboarding-nav';
import { ResignationReviewDialog } from '@/features/offboarding/components/resignation-review-dialog';
import { StatusPill } from '@/features/offboarding/components/status-pill';
import {
    formatDay,
    RESIGNATION_FILTERS,
    RESIGNATION_STATUS_LABELS,
    RESIGNATION_STATUS_STYLES,
} from '@/features/offboarding/constants';
import { offboardingRoutes } from '@/features/offboarding/routes';
import type {
    ResignationRow,
    ResignationsPageProps,
} from '@/features/offboarding/types';
import { useLinkedRecord } from '@/hooks/use-linked-record';
import { cn } from '@/lib/utils';

/**
 * Resignations employees filed themselves (ADR 0076), waiting ones first: who,
 * when, the last day they propose and the notice that gives. A row opens the
 * resignation, to accept — their exit starts — or decline with a note.
 */
export default function ResignationsPage() {
    const { resignations, stats, options, notice_days, can, nav } =
        usePage<ResignationsPageProps>().props;

    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<string>('pending');
    const [department, setDepartment] = useState<string>('all');
    const [selected, setSelected] = useState<ResignationRow | null>(null);
    const [open, setOpen] = useState(false);

    const show = (row: ResignationRow) => {
        setSelected(row);
        setOpen(true);
    };

    useLinkedRecord(resignations, (row) => row.id, show);

    const filtered =
        search !== '' || status !== 'pending' || department !== 'all';

    const rows = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return resignations.filter(
            (row) =>
                (status === 'all' || row.status === status) &&
                (department === 'all' ||
                    String(row.employee.department_id) === department) &&
                (needle === '' ||
                    row.employee.full_name.toLowerCase().includes(needle) ||
                    row.employee.employee_no.toLowerCase().includes(needle)),
        );
    }, [resignations, search, status, department]);

    const page = useClientPagination(
        rows,
        [search, status, department].join('|'),
    );

    return (
        <>
            <Head title="Resignations" />

            <PageBody>
                <PageHeader
                    title="Resignations"
                    description="Resignations your people filed themselves. Accept one to start the exit, or decline it with a note."
                    actions={
                        <OffboardingNav current="resignations" counts={nav} />
                    }
                />

                <StatTiles
                    tiles={[
                        {
                            key: 'pending',
                            label: 'Waiting for you',
                            value: stats.pending,
                            icon: Hourglass,
                            accent: 'amber',
                        },
                        {
                            key: 'short',
                            label: 'Short notice',
                            value: stats.short_notice,
                            hint: `under ${notice_days} days`,
                            icon: TriangleAlert,
                            accent: stats.short_notice > 0 ? 'rose' : 'slate',
                        },
                        {
                            key: 'accepted',
                            label: 'Accepted',
                            value: stats.accepted_30d,
                            hint: 'last 30 days',
                            icon: CircleCheck,
                            accent: 'emerald',
                        },
                        {
                            key: 'withdrawn',
                            label: 'Withdrawn',
                            value: stats.withdrawn_30d,
                            hint: 'last 30 days',
                            icon: Undo2,
                            accent: 'slate',
                        },
                    ]}
                />

                <div className="flex flex-col gap-3">
                    <ListToolbar
                        filtered={filtered}
                        onReset={() => {
                            setSearch('');
                            setStatus('pending');
                            setDepartment('all');
                        }}
                    >
                        <SearchInput
                            value={search}
                            onSearch={setSearch}
                            placeholder="Search by name or no.…"
                            label="Search resignations"
                        />
                        <FilterSelect
                            label="Filter by department"
                            value={department}
                            onChange={setDepartment}
                            options={[
                                { value: 'all', label: 'All departments' },
                                ...options.departments.map((d) => ({
                                    value: String(d.id),
                                    label: d.name,
                                })),
                            ]}
                            className="w-44"
                        />
                        <FilterSelect
                            label="Filter by status"
                            value={status}
                            onChange={setStatus}
                            options={[...RESIGNATION_FILTERS]}
                            className="w-40"
                        />
                    </ListToolbar>

                    <TableCard>
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Department</TableHead>
                                    <TableHead>Filed</TableHead>
                                    <TableHead>Last day proposed</TableHead>
                                    <TableHead className="text-right">
                                        Notice
                                    </TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Decided</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {page.rows.length === 0 && (
                                    <EmptyTableRow
                                        colSpan={7}
                                        icon={ClipboardList}
                                        title={
                                            filtered
                                                ? 'Nothing matches'
                                                : 'No resignations waiting'
                                        }
                                        description={
                                            filtered
                                                ? 'Try another filter, or reset them.'
                                                : 'When someone files a resignation from the web or the app, it lands here for you to decide.'
                                        }
                                    />
                                )}
                                {page.rows.map((row) => (
                                    <TableRow
                                        key={row.id}
                                        {...rowOpens(() => show(row))}
                                    >
                                        <TableCell>
                                            <div className="flex items-center gap-2.5">
                                                <PersonAvatar
                                                    name={
                                                        row.employee.full_name
                                                    }
                                                    initials={
                                                        row.employee.initials
                                                    }
                                                    photo={row.employee.photo}
                                                    className="size-8"
                                                />
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {row.employee.full_name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {row.employee
                                                            .position ??
                                                            row.employee
                                                                .employee_no}
                                                    </p>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {row.employee.department ?? '—'}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            {formatDay(row.filed_on)}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            {formatDay(row.proposed_last_day)}
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                'text-right tabular-nums',
                                                row.notice_days < notice_days &&
                                                    row.status === 'pending' &&
                                                    'font-medium text-amber-600 dark:text-amber-400',
                                            )}
                                        >
                                            {row.notice_days} days
                                        </TableCell>
                                        <TableCell>
                                            <StatusPill
                                                label={
                                                    RESIGNATION_STATUS_LABELS[
                                                        row.status
                                                    ]
                                                }
                                                className={
                                                    RESIGNATION_STATUS_STYLES[
                                                        row.status
                                                    ]
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                                            {row.decided_on
                                                ? `${formatDay(row.decided_on)}${row.decided_by ? ` · ${row.decided_by}` : ''}`
                                                : '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </DataTable>
                    </TableCard>

                    <TablePagination
                        meta={page.meta}
                        perPage={page.perPage}
                        onPage={page.setPage}
                        onPerPage={page.setPerPage}
                    />
                </div>
            </PageBody>

            <ResignationReviewDialog
                resignation={selected}
                open={open}
                onOpenChange={setOpen}
                programs={options.programs}
                noticeDays={notice_days}
                canManage={can.manage}
            />
        </>
    );
}

ResignationsPage.layout = {
    breadcrumbs: [
        { title: 'Offboarding', href: offboardingRoutes.index },
        { title: 'Resignations', href: offboardingRoutes.resignations },
    ],
};
