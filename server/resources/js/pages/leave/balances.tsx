import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    PageBody,
    PageHeader,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import { AdjustBalanceDialog } from '@/features/leave/components/adjust-balance-dialog';
import { BalanceToolbar } from '@/features/leave/components/balance-toolbar';
import { BalancesTable } from '@/features/leave/components/balances-table';
import { LeaveNav } from '@/features/leave/components/leave-nav';
import { leaveRoutes } from '@/features/leave/routes';
import type {
    BalancesPageProps,
    EmployeeBalance,
} from '@/features/leave/types';

export default function LeaveBalances() {
    const { types, employees, year, years, options, can, filters } =
        usePage<BalancesPageProps>().props;
    const page = useClientPagination(
        employees,
        [year, filters.search, filters.department].join('|'),
    );

    const [adjustId, setAdjustId] = useState<number | null>(null);
    const [adjustOpen, setAdjustOpen] = useState(false);

    const adjustEmployee = useMemo(
        () => employees.find((e) => e.id === adjustId) ?? null,
        [employees, adjustId],
    );

    const apply = (overrides: {
        year?: number;
        search?: string;
        department?: number | null;
    }) => {
        const next = {
            year,
            search: filters.search,
            department: filters.department,
            ...overrides,
        };
        const query: Record<string, string | number> = {};

        if (next.year) {
            query.year = next.year;
        }

        if (next.search.trim()) {
            query.search = next.search.trim();
        }

        if (next.department) {
            query.department = next.department;
        }

        router.get(leaveRoutes.balances, query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['employees', 'year', 'filters'],
        });
    };

    const openAdjust = (employee: EmployeeBalance) => {
        setAdjustId(employee.id);
        setAdjustOpen(true);
    };

    return (
        <>
            <Head title="Leave Balances" />

            <PageBody>
                <PageHeader
                    title="Leave Balances"
                    description={`Each employee's ${year} entitlement and what is left of it, per leave type.`}
                    actions={<LeaveNav active="balances" />}
                />

                <div className="flex flex-col gap-3">
                    <BalanceToolbar
                        search={filters.search}
                        year={year}
                        years={years}
                        department={filters.department}
                        departments={options.departments}
                        onSearch={(search) => apply({ search })}
                        onYear={(value) => apply({ year: value })}
                        onDepartment={(department) => apply({ department })}
                        onReset={() => apply({ search: '', department: null })}
                    />

                    <BalancesTable
                        employees={page.rows}
                        types={types}
                        year={year}
                        canManage={can.manage}
                        filtered={
                            filters.search !== '' || filters.department !== null
                        }
                        onAdjust={openAdjust}
                    />

                    <TablePagination
                        meta={page.meta}
                        perPage={page.perPage}
                        onPage={page.setPage}
                        onPerPage={page.setPerPage}
                    />
                </div>
            </PageBody>

            <AdjustBalanceDialog
                employee={adjustEmployee}
                year={year}
                open={adjustOpen}
                onOpenChange={setAdjustOpen}
            />
        </>
    );
}

LeaveBalances.layout = {
    breadcrumbs: [
        { title: 'Leave Management', href: '/leave' },
        { title: 'Balances', href: '/leave/balances' },
    ],
};
