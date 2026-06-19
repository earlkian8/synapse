import { Head, usePage } from '@inertiajs/react';
import { UserRoundMinus } from 'lucide-react';
import { useState } from 'react';
import { InitiateOffboardingSheet } from '@/features/offboarding/components/initiate-offboarding-sheet';
import { OffboardingCaseCard } from '@/features/offboarding/components/offboarding-case-card';
import { OffboardingStatsCards } from '@/features/offboarding/components/offboarding-stats';
import { OffboardingToolbar } from '@/features/offboarding/components/offboarding-toolbar';
import { useOffboardingFilters } from '@/features/offboarding/hooks/use-offboarding-filters';
import type { IndexPageProps } from '@/features/offboarding/types';

export default function OffboardingIndex() {
    const { cases, stats, options, can, filters } =
        usePage<IndexPageProps>().props;
    const { setSearch, setStatus, setType, setDepartment, reset } =
        useOffboardingFilters(filters);

    const [startOpen, setStartOpen] = useState(false);

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
                        onSearch={setSearch}
                        onStatus={setStatus}
                        onType={setType}
                        onDepartment={setDepartment}
                        onReset={reset}
                        onStart={() => setStartOpen(true)}
                    />

                    {cases.length === 0 ? (
                        <EmptyState />
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
