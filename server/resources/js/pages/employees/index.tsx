import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/features/employees/components/confirm-dialog';
import { EmployeeBulkActionsBar } from '@/features/employees/components/employee-bulk-actions-bar';
import { EmployeeDetailSheet } from '@/features/employees/components/employee-detail-sheet';
import { EmployeeFormSheet } from '@/features/employees/components/employee-form-sheet';
import { EmployeesPagination } from '@/features/employees/components/employees-pagination';
import { EmployeesStats } from '@/features/employees/components/employees-stats';
import { EmployeesTable } from '@/features/employees/components/employees-table';
import { EmployeesToolbar } from '@/features/employees/components/employees-toolbar';
import { useEmployeesFilters } from '@/features/employees/hooks/use-employees-filters';
import { employeeRoutes } from '@/features/employees/routes';
import type {
    BulkEmployeeAction,
    EmployeesPageProps,
    ManagedEmployee,
} from '@/features/employees/types';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

export default function EmployeesIndex() {
    const { employees, stats, options, can, filters } =
        usePage<EmployeesPageProps>().props;
    const {
        setSearch,
        setStatus,
        setType,
        setDepartment,
        setPerPage,
        setPage,
        toggleSort,
        reset,
    } = useEmployeesFilters(filters);

    const rows = employees.data;

    const [selected, setSelected] = useState<number[]>([]);
    const [formEmployee, setFormEmployee] = useState<ManagedEmployee | null>(
        null,
    );
    const [formOpen, setFormOpen] = useState(false);
    const [detailEmployee, setDetailEmployee] =
        useState<ManagedEmployee | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [highlightId, setHighlightId] = useState<number | null>(null);

    // The agentic assistant fires this after it creates/updates an employee so
    // the freshly-reloaded row briefly flashes into view.
    useEffect(() => {
        const onMutated = (event: Event) => {
            const id = (event as CustomEvent<{ id: number }>).detail?.id;

            if (typeof id === 'number') {
                setHighlightId(id);
                window.setTimeout(() => setHighlightId(null), 2600);
            }
        };

        window.addEventListener('synapse:employee-mutated', onMutated);

        return () =>
            window.removeEventListener('synapse:employee-mutated', onMutated);
    }, []);

    // Drop stale selections whenever the result set changes.
    const signature = useMemo(
        () =>
            [
                filters.search,
                filters.status,
                filters.type,
                filters.department,
                filters.sort,
                filters.direction,
                filters.per_page,
                employees.meta.current_page,
            ].join('|'),
        [filters, employees.meta.current_page],
    );
    const [scope, setScope] = useState(signature);

    if (signature !== scope) {
        setScope(signature);
        setSelected([]);
    }

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

    // ── Single-row actions ─────────────────────────────────────────────────
    const openCreate = () => {
        setFormEmployee(null);
        setFormOpen(true);
    };

    const openEdit = (employee: ManagedEmployee) => {
        setDetailOpen(false);
        setFormEmployee(employee);
        setFormOpen(true);
    };

    const openView = (employee: ManagedEmployee) => {
        setDetailEmployee(employee);
        setDetailOpen(true);
    };

    const archive = (employee: ManagedEmployee) =>
        askConfirm({
            title: `Archive ${employee.full_name}?`,
            description:
                'The record will be hidden from the main directory. You can restore it later from the Archived filter.',
            confirmLabel: 'Archive employee',
            run: () =>
                router.delete(
                    employeeRoutes.destroy(employee.id),
                    withProcessing,
                ),
        });

    const restore = (employee: ManagedEmployee) =>
        router.patch(
            employeeRoutes.restore(employee.id),
            {},
            { preserveScroll: true },
        );

    const remove = (employee: ManagedEmployee) =>
        askConfirm({
            title: `Permanently delete ${employee.full_name}?`,
            description:
                'This cannot be undone. All records and files associated with this employee will be removed.',
            confirmLabel: 'Delete permanently',
            run: () =>
                router.delete(
                    employeeRoutes.forceDelete(employee.id),
                    withProcessing,
                ),
        });

    // ── Bulk actions ───────────────────────────────────────────────────────
    const runBulk = (action: BulkEmployeeAction, status?: string) =>
        router.post(
            employeeRoutes.bulk,
            { action, ids: selected, status },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setConfirmOpen(false);
                    setSelected([]);
                },
            },
        );

    const handleBulk = (action: BulkEmployeeAction, status?: string) => {
        const count = selected.length;
        const noun = count === 1 ? 'employee' : 'employees';

        if (action === 'archive') {
            return askConfirm({
                title: `Archive ${count} ${noun}?`,
                description: `${count} ${noun} will be moved to the archive.`,
                confirmLabel: 'Archive',
                run: () => runBulk('archive'),
            });
        }

        if (action === 'delete') {
            return askConfirm({
                title: `Permanently delete ${count} ${noun}?`,
                description: `This cannot be undone. ${count} ${noun} will be removed for good.`,
                confirmLabel: 'Delete permanently',
                run: () => runBulk('delete'),
            });
        }

        runBulk(action, status);
    };

    // ── Selection ──────────────────────────────────────────────────────────
    const toggleAll = (checked: boolean) =>
        setSelected(checked ? rows.map((e) => e.id) : []);
    const toggleRow = (id: number, checked: boolean) =>
        setSelected((current) =>
            checked ? [...current, id] : current.filter((x) => x !== id),
        );

    return (
        <>
            <Head title="Employees" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Employees
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Manage your organisation's workforce, records and
                        placements.
                    </p>
                </div>

                <EmployeesStats stats={stats} />

                <div className="flex flex-col gap-4">
                    <EmployeesToolbar
                        filters={filters}
                        departments={options.departments}
                        canCreate={can.create}
                        canExport={can.export}
                        onSearch={setSearch}
                        onStatus={setStatus}
                        onType={setType}
                        onDepartment={setDepartment}
                        onReset={reset}
                        onCreate={openCreate}
                    />

                    {selected.length > 0 && (
                        <EmployeeBulkActionsBar
                            count={selected.length}
                            scope={filters.status}
                            can={can}
                            onAction={handleBulk}
                            onClear={() => setSelected([])}
                        />
                    )}

                    <EmployeesTable
                        employees={rows}
                        filters={filters}
                        selected={selected}
                        can={can}
                        highlightId={highlightId}
                        onToggleSort={toggleSort}
                        onToggleAll={toggleAll}
                        onToggleRow={toggleRow}
                        onView={openView}
                        onEdit={openEdit}
                        onArchive={archive}
                        onRestore={restore}
                        onDelete={remove}
                    />

                    <EmployeesPagination
                        meta={employees.meta}
                        perPage={filters.per_page}
                        onPage={setPage}
                        onPerPage={setPerPage}
                    />
                </div>
            </div>

            <EmployeeFormSheet
                employee={formEmployee}
                options={options}
                open={formOpen}
                onOpenChange={setFormOpen}
            />

            <EmployeeDetailSheet
                employee={detailEmployee}
                open={detailOpen}
                canEdit={can.update}
                canManageDocuments={can.manageDocuments}
                onOpenChange={setDetailOpen}
                onEdit={openEdit}
            />

            {confirm && (
                <ConfirmDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    title={confirm.title}
                    description={confirm.description}
                    confirmLabel={confirm.confirmLabel}
                    destructive
                    processing={processing}
                    onConfirm={confirm.run}
                />
            )}
        </>
    );
}

EmployeesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Employees',
            href: '/employees',
        },
    ],
};
