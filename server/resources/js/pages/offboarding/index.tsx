import { Head, router, usePage } from '@inertiajs/react';
import { Download, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import {
    FilterSelect,
    ListToolbar,
    PageBody,
    PageHeader,
    SearchInput,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { CaseTable } from '@/features/offboarding/components/case-table';
import type { CaseSort } from '@/features/offboarding/components/case-table';
import { ConfirmDialog } from '@/features/offboarding/components/confirm-dialog';
import { InitiateOffboardingDialog } from '@/features/offboarding/components/initiate-offboarding-dialog';
import { OffboardingStatsCards } from '@/features/offboarding/components/offboarding-stats';
import {
    DEFAULT_FILTERS,
    STATUS_FILTERS,
    TYPE_OPTIONS,
} from '@/features/offboarding/constants';
import { useOffboardingFilters } from '@/features/offboarding/hooks/use-offboarding-filters';
import { offboardingRoutes } from '@/features/offboarding/routes';
import type {
    CaseStatus,
    IndexPageProps,
    OffboardingCase,
    OffboardingFilters,
} from '@/features/offboarding/types';

/** Rank a case by its lifecycle for the table's status sort. */
const STATUS_RANK: Record<CaseStatus, number> = {
    initiated: 0,
    clearance: 1,
    completed: 2,
    cancelled: 3,
};

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    run: () => void;
};

/** The export URL carrying the table's current filters, so CSV = what you see. */
function exportUrl(filters: OffboardingFilters): string {
    const params = new URLSearchParams();

    if (filters.search) {
        params.set('search', filters.search);
    }

    if (filters.status && filters.status !== DEFAULT_FILTERS.status) {
        params.set('status', filters.status);
    }

    if (filters.type) {
        params.set('type', filters.type);
    }

    if (filters.department) {
        params.set('department', String(filters.department));
    }

    const query = params.toString();

    return query
        ? `${offboardingRoutes.export}?${query}`
        : offboardingRoutes.export;
}

/** The employment status an exit type lands the employee in on completion. */
function separationLabel(type: OffboardingCase['type']): string {
    return type === 'termination' ? 'terminated' : 'resigned';
}

/**
 * Offboarding: everyone leaving, as one table. A row opens that person's
 * clearance; its menu completes, reopens, cancels or removes the exit.
 */
export default function OffboardingIndex() {
    const { cases, stats, options, can, filters } =
        usePage<IndexPageProps>().props;
    const table = useOffboardingFilters(filters);

    const [sort, setSort] = useState<CaseSort>('last_day');
    const [direction, setDirection] = useState<'asc' | 'desc'>('asc');
    const [startOpen, setStartOpen] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const filtered =
        filters.search !== '' ||
        filters.status !== DEFAULT_FILTERS.status ||
        filters.type !== null ||
        filters.department !== null;

    const onSort = (key: CaseSort) => {
        if (key === sort) {
            setDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSort(key);
            setDirection(key === 'clearance' ? 'desc' : 'asc');
        }
    };

    // The server filters; the table sorts and pages what it sends.
    const sorted = useMemo(() => {
        const dir = direction === 'asc' ? 1 : -1;
        const day = (iso: string | null) =>
            iso ? new Date(iso).getTime() : Number.MAX_SAFE_INTEGER;

        return [...cases].sort((a, b) => {
            switch (sort) {
                case 'employee':
                    return (
                        (a.employee?.full_name ?? '').localeCompare(
                            b.employee?.full_name ?? '',
                        ) * dir
                    );
                case 'type':
                    return a.type.localeCompare(b.type) * dir;
                case 'clearance':
                    return (a.clearance.percent - b.clearance.percent) * dir;
                case 'status':
                    return (
                        (STATUS_RANK[a.status] - STATUS_RANK[b.status]) * dir
                    );
                default:
                    return (
                        (day(a.last_working_day) - day(b.last_working_day)) *
                        dir
                    );
            }
        });
    }, [cases, sort, direction]);

    const page = useClientPagination(
        sorted,
        [
            filters.search,
            filters.status,
            filters.type,
            filters.department,
            sort,
            direction,
        ].join('|'),
    );

    const askConfirm = (config: ConfirmConfig) => {
        setConfirm(config);
        setConfirmOpen(true);
    };

    const withProcessing = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => {
            setProcessing(false);
            setConfirmOpen(false);
        },
    };

    const who = (item: OffboardingCase) =>
        item.employee?.full_name ?? 'this employee';

    const setStatus = (
        item: OffboardingCase,
        action: 'complete' | 'cancel' | 'reopen',
        options = { preserveScroll: true },
    ) =>
        router.patch(
            offboardingRoutes.status(item.hashid),
            { action },
            options,
        );

    const complete = (item: OffboardingCase) => {
        const outstanding = item.clearance.total - item.clearance.cleared;

        askConfirm({
            title: `Complete the exit for ${who(item)}?`,
            description: (
                <>
                    They will be marked{' '}
                    <span className="font-medium text-foreground">
                        {separationLabel(item.type)}
                    </span>{' '}
                    and their last working day finalised.
                    {outstanding > 0 && (
                        <>
                            {' '}
                            <span className="font-medium text-rose-600 dark:text-rose-400">
                                {outstanding} clearance item
                                {outstanding === 1 ? '' : 's'}
                            </span>{' '}
                            still outstanding.
                        </>
                    )}
                </>
            ),
            confirmLabel: 'Complete exit',
            run: () => setStatus(item, 'complete', withProcessing),
        });
    };

    const cancel = (item: OffboardingCase) =>
        askConfirm({
            title: `Cancel offboarding for ${who(item)}?`,
            description:
                'The exit is marked cancelled and the employee returns to active. You can reopen it later.',
            confirmLabel: 'Cancel offboarding',
            destructive: true,
            run: () => setStatus(item, 'cancel', withProcessing),
        });

    const remove = (item: OffboardingCase) =>
        askConfirm({
            title: `Delete offboarding for ${who(item)}?`,
            description:
                'This permanently removes the exit case and its clearance items. It cannot be undone.',
            confirmLabel: 'Delete offboarding',
            destructive: true,
            run: () =>
                router.delete(
                    offboardingRoutes.destroy(item.hashid),
                    withProcessing,
                ),
        });

    return (
        <>
            <Head title="Offboarding" />

            <PageBody>
                <PageHeader
                    title="Offboarding"
                    description="Employee exits end to end — clearance sign-offs, final dates and a clean separation."
                />

                <OffboardingStatsCards stats={stats} />

                <div className="flex flex-col gap-3">
                    <ListToolbar
                        filtered={filtered}
                        onReset={table.reset}
                        actions={
                            <>
                                <Button variant="outline" size="sm" asChild>
                                    <a href={exportUrl(filters)}>
                                        <Download className="size-4" />
                                        Export
                                    </a>
                                </Button>
                                {can.manage && (
                                    <Button
                                        size="sm"
                                        onClick={() => setStartOpen(true)}
                                    >
                                        <Plus className="size-4" />
                                        Start offboarding
                                    </Button>
                                )}
                            </>
                        }
                    >
                        <SearchInput
                            value={filters.search}
                            onSearch={table.setSearch}
                            placeholder="Search by name or no.…"
                            label="Search offboarding"
                        />
                        <FilterSelect
                            label="Filter by exit type"
                            value={filters.type ?? 'all'}
                            onChange={(value) =>
                                table.setType(value === 'all' ? null : value)
                            }
                            options={[
                                { value: 'all', label: 'All exit types' },
                                ...TYPE_OPTIONS,
                            ]}
                        />
                        <FilterSelect
                            label="Filter by department"
                            value={
                                filters.department
                                    ? String(filters.department)
                                    : 'all'
                            }
                            onChange={(value) =>
                                table.setDepartment(
                                    value === 'all' ? null : Number(value),
                                )
                            }
                            options={[
                                { value: 'all', label: 'All departments' },
                                ...options.departments.map((department) => ({
                                    value: String(department.id),
                                    label: department.name,
                                })),
                            ]}
                            className="w-44"
                        />
                        <FilterSelect
                            label="Filter by status"
                            value={filters.status}
                            onChange={table.setStatus}
                            options={STATUS_FILTERS}
                            className="w-36"
                        />
                    </ListToolbar>

                    <CaseTable
                        cases={page.rows}
                        sort={sort}
                        direction={direction}
                        onSort={onSort}
                        canManage={can.manage}
                        filtered={filtered}
                        onStart={
                            can.manage ? () => setStartOpen(true) : undefined
                        }
                        onComplete={complete}
                        onReopen={(item) => setStatus(item, 'reopen')}
                        onCancel={cancel}
                        onDelete={remove}
                    />

                    <TablePagination
                        meta={page.meta}
                        perPage={page.perPage}
                        onPage={page.setPage}
                        onPerPage={page.setPerPage}
                    />
                </div>
            </PageBody>

            <InitiateOffboardingDialog
                employees={options.employees}
                programs={options.programs}
                open={startOpen}
                onOpenChange={setStartOpen}
            />

            {confirm && (
                <ConfirmDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    title={confirm.title}
                    description={confirm.description}
                    confirmLabel={confirm.confirmLabel}
                    destructive={confirm.destructive}
                    processing={processing}
                    onConfirm={confirm.run}
                />
            )}
        </>
    );
}

OffboardingIndex.layout = {
    breadcrumbs: [{ title: 'Offboarding', href: offboardingRoutes.index }],
};
