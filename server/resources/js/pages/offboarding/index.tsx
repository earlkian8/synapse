import { Head, usePage } from '@inertiajs/react';
import { UserRoundMinus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { CaseTable } from '@/features/offboarding/components/case-table';
import type { CaseSort } from '@/features/offboarding/components/case-table';
import { InitiateOffboardingSheet } from '@/features/offboarding/components/initiate-offboarding-sheet';
import { OffboardingCaseCard } from '@/features/offboarding/components/offboarding-case-card';
import { OffboardingStatsCards } from '@/features/offboarding/components/offboarding-stats';
import { OffboardingToolbar } from '@/features/offboarding/components/offboarding-toolbar';
import { useCasesView } from '@/features/offboarding/hooks/use-cases-view';
import { useOffboardingFilters } from '@/features/offboarding/hooks/use-offboarding-filters';
import type { CaseStatus, IndexPageProps } from '@/features/offboarding/types';

/** Rank a case by its lifecycle for the table's status sort. */
const STATUS_RANK: Record<CaseStatus, number> = {
    initiated: 0,
    clearance: 1,
    completed: 2,
    cancelled: 3,
};

export default function OffboardingIndex() {
    const { cases, stats, options, can, filters } =
        usePage<IndexPageProps>().props;
    const { setSearch, setStatus, setType, setDepartment, reset } =
        useOffboardingFilters(filters);

    const { view, changeView } = useCasesView();
    const [sort, setSort] = useState<CaseSort>('last_day');
    const [direction, setDirection] = useState<'asc' | 'desc'>('asc');
    const [startOpen, setStartOpen] = useState(false);

    const onSort = (key: CaseSort) => {
        if (key === sort) {
            setDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSort(key);
            setDirection(key === 'clearance' ? 'desc' : 'asc');
        }
    };

    // Table layout: the (server-filtered) list, client-sorted.
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

    return (
        <>
            <Head title="Offboarding" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Offboarding
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Manage employee exits end to end — clearance sign-offs,
                        final dates and a clean separation.
                    </p>
                </div>

                <OffboardingStatsCards stats={stats} />

                <div className="flex flex-col gap-4">
                    <OffboardingToolbar
                        filters={filters}
                        departments={options.departments}
                        canManage={can.manage}
                        view={view}
                        onSearch={setSearch}
                        onStatus={setStatus}
                        onType={setType}
                        onDepartment={setDepartment}
                        onReset={reset}
                        onStart={() => setStartOpen(true)}
                        onView={changeView}
                    />

                    {cases.length === 0 ? (
                        <EmptyState />
                    ) : view === 'table' ? (
                        <CaseTable
                            cases={sorted}
                            sort={sort}
                            direction={direction}
                            onSort={onSort}
                        />
                    ) : (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {cases.map((c) => (
                                <OffboardingCaseCard key={c.id} case={c} />
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <InitiateOffboardingSheet
                employees={options.employees}
                programs={options.programs}
                open={startOpen}
                onOpenChange={setStartOpen}
            />
        </>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <UserRoundMinus className="size-5" />
            </span>
            <p className="text-sm font-medium">No offboarding here</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                When an employee is leaving, start their offboarding to generate
                a clearance checklist and track them to a clean exit.
            </p>
        </div>
    );
}

OffboardingIndex.layout = {
    breadcrumbs: [{ title: 'Offboarding', href: '/offboarding' }],
};
