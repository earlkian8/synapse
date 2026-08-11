import { Head, router, usePage } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AdjustBalanceSheet } from '@/features/leave/components/adjust-balance-sheet';
import { BalanceToolbar } from '@/features/leave/components/balance-toolbar';
import { EmployeeBalanceCard } from '@/features/leave/components/employee-balance-card';
import { LeaveNav } from '@/features/leave/components/leave-nav';
import { leaveRoutes } from '@/features/leave/routes';
import type {
    BalancesPageProps,
    EmployeeBalance,
} from '@/features/leave/types';

export default function LeaveBalances() {
    const { employees, year, years, options, can, filters } =
        usePage<BalancesPageProps>().props;

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

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Leave Balances
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Each employee's entitlement, used and remaining days
                            per leave type.
                        </p>
                    </div>
                    <LeaveNav active="balances" />
                </div>

                <BalanceToolbar
                    search={filters.search}
                    year={year}
                    years={years}
                    department={filters.department}
                    departments={options.departments}
                    onSearch={(search) => apply({ search })}
                    onYear={(value) => apply({ year: value })}
                    onDepartment={(department) => apply({ department })}
                />

                {employees.length === 0 ? (
                    <EmptyState />
                ) : (
                    <div className="flex flex-col gap-2.5">
                        {employees.map((employee) => (
                            <EmployeeBalanceCard
                                key={employee.id}
                                employee={employee}
                                canManage={can.manage}
                                onAdjust={openAdjust}
                            />
                        ))}
                    </div>
                )}
            </div>

            <AdjustBalanceSheet
                employee={adjustEmployee}
                year={year}
                open={adjustOpen}
                onOpenChange={setAdjustOpen}
            />
        </>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <Users className="size-5" />
            </span>
            <p className="text-sm font-medium">No employees</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                No employees match this view.
            </p>
        </div>
    );
}

LeaveBalances.layout = {
    breadcrumbs: [
        { title: 'Leave Management', href: '/leave' },
        { title: 'Balances', href: '/leave/balances' },
    ],
};
